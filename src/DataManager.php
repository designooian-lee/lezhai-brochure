<?php
declare(strict_types=1);

namespace Lezhai;

use PDO;
use RuntimeException;
use ZipArchive;

final class DataManager
{
    public function __construct(private readonly PDO $pdo) {}

    public function export(): array
    {
        $categories = $this->pdo->query('SELECT id,name,slug,sort_order,is_active FROM categories ORDER BY id')->fetchAll();
        $rows = $this->pdo->query('SELECT id,category_id,source_url,source_type,name,description,cover_path,cover_source_url,page_manifest,pdf_url,manual_priority,is_active,parse_status,parse_error,view_count,parsed_at,reader_mode,local_page_count FROM catalogs ORDER BY id')->fetchAll();
        $catalogs = array_map(static function (array $row): array {
            $row['page_manifest'] = json_decode((string) $row['page_manifest'], true) ?: [];
            return $row;
        }, $rows);
        $views = $this->pdo->query('SELECT catalog_id,viewed_on,visitor_hash,created_at FROM catalog_daily_views ORDER BY catalog_id,viewed_on,visitor_hash')->fetchAll();
        $tutorials = $this->pdo->query('SELECT id,title,description,body,cover_path,manual_priority,is_active FROM tutorials ORDER BY id')->fetchAll();
        $tutorialMedia = $this->pdo->query('SELECT id,tutorial_id,media_type,source_type,title,url,file_path,mime_type,sort_order FROM tutorial_media ORDER BY id')->fetchAll();
        $articles = $this->pdo->query('SELECT id,title,slug,excerpt,body_html,cover_path,seo_title,seo_keywords,meta_description,status,published_at,created_at,updated_at FROM articles ORDER BY id')->fetchAll();
        $articleMonthlyViews = $this->pdo->query('SELECT article_id,viewed_month,view_count,updated_at FROM article_monthly_views ORDER BY article_id,viewed_month')->fetchAll();
        return [
            'format' => 'lezhai-brochure-data',
            'version' => 1,
            'exported_at' => date(DATE_ATOM),
            'categories' => $categories,
            'catalogs' => $catalogs,
            'daily_views' => $views,
            'tutorials' => $tutorials,
            'tutorial_media' => $tutorialMedia,
            'articles' => $articles,
            'article_monthly_views' => $articleMonthlyViews,
        ];
    }

    public function createBackupZip(bool $includeLocalPages = true): string
    {
        $directory = dirname(__DIR__) . '/storage/runtime/backups';
        @mkdir($directory, 0775, true);
        $target = $directory . '/lezhai-brochure-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('无法创建备份 ZIP。');
        try {
            $json = json_encode($this->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $zip->addFromString('data.json', $json);
            foreach ($this->pdo->query('SELECT id,cover_path FROM catalogs ORDER BY id')->fetchAll() as $catalog) {
                $cover = (string) $catalog['cover_path'];
                if (str_starts_with($cover, '/uploads/covers/')) {
                    $file = dirname(__DIR__) . '/public' . $cover;
                    if (is_file($file)) $this->addStoredFile($zip, $file, 'covers/' . basename($file));
                }
                if ($includeLocalPages) {
                    foreach (glob(dirname(__DIR__) . '/storage/local-pages/' . (int) $catalog['id'] . '/*') ?: [] as $page) {
                        if (is_file($page) && preg_match('~^\d{4}\.(?:jpg|jpeg|png|webp)$~i', basename($page))) {
                            $this->addStoredFile($zip, $page, 'local-pages/' . (int) $catalog['id'] . '/' . basename($page));
                        }
                    }
                }
            }
            foreach ($this->pdo->query('SELECT cover_path FROM tutorials UNION ALL SELECT file_path FROM tutorial_media')->fetchAll() as $asset) {
                $path=(string)array_values($asset)[0];
                if (str_starts_with($path,'/uploads/tutorials/')) { $file=dirname(__DIR__).'/public'.$path; if(is_file($file))$this->addStoredFile($zip,$file,'tutorials/'.basename($file)); }
            }
            foreach ($this->pdo->query('SELECT cover_path FROM articles')->fetchAll() as $asset) {
                $path=(string)$asset['cover_path']; if(str_starts_with($path,'/uploads/articles/')){$file=dirname(__DIR__).'/public'.$path;if(is_file($file))$this->addStoredFile($zip,$file,'articles/'.basename($file));}
            }
            foreach(glob(dirname(__DIR__).'/public/uploads/articles/*')?:[] as $file)if(is_file($file)&&$zip->locateName('articles/'.basename($file))===false)$this->addStoredFile($zip,$file,'articles/'.basename($file));
        } catch (\Throwable $e) {
            $zip->close(); @unlink($target); throw $e;
        }
        $zip->close();
        return $target;
    }

    public function importBackupZip(string $file): array
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new RuntimeException('备份 ZIP 无法打开。');
        $json = $zip->getFromName('data.json');
        if (!is_string($json)) { $zip->close(); throw new RuntimeException('备份 ZIP 缺少 data.json。'); }
        try { $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $zip->close(); throw new RuntimeException('备份中的 data.json 无效。'); }
        if (!is_array($data)) { $zip->close(); throw new RuntimeException('备份数据结构不正确。'); }

        $staging = dirname(__DIR__) . '/storage/runtime/import-' . bin2hex(random_bytes(5));
        @mkdir($staging, 0775, true);
        $total = 0;
        $closed = false;
        try {
            if ($zip->numFiles > 100000) throw new RuntimeException('备份文件条目过多。');
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                if ($name === 'data.json' || str_ends_with($name, '/')) continue;
                if (!preg_match('~^(covers/[A-Za-z0-9._-]+|tutorials/[A-Za-z0-9._-]+|articles/[A-Za-z0-9._-]+|local-pages/\d+/\d{4}\.(?:jpg|jpeg|png|webp))$~i', $name)) {
                    throw new RuntimeException('备份 ZIP 含有不允许的文件路径。');
                }
                $total += $size;
                if ($total > 5 * 1024 * 1024 * 1024) throw new RuntimeException('备份解压后超过 5GB。');
                $target = $staging . '/' . str_replace('/', DIRECTORY_SEPARATOR, $name);
                @mkdir(dirname($target), 0775, true);
                $input = $zip->getStream($name); $output = fopen($target, 'wb');
                if (!is_resource($input) || !is_resource($output)) throw new RuntimeException('无法读取备份文件：' . $name);
                stream_copy_to_stream($input, $output); fclose($input); fclose($output);
            }
            $zip->close();
            $closed = true;
            $result = $this->import($data);
            $coverRoot = dirname(__DIR__) . '/public/uploads/covers'; @mkdir($coverRoot, 0775, true);
            foreach (glob($staging . '/covers/*') ?: [] as $cover) if (is_file($cover)) @copy($cover, $coverRoot . '/' . basename($cover));
            $tutorialRoot=dirname(__DIR__).'/public/uploads/tutorials'; @mkdir($tutorialRoot,0775,true);
            foreach(glob($staging.'/tutorials/*')?:[] as $asset)if(is_file($asset))@copy($asset,$tutorialRoot.'/'.basename($asset));
            $articleRoot=dirname(__DIR__).'/public/uploads/articles'; @mkdir($articleRoot,0775,true);
            foreach(glob($staging.'/articles/*')?:[] as $asset)if(is_file($asset))@copy($asset,$articleRoot.'/'.basename($asset));
            $localRoot = dirname(__DIR__) . '/storage/local-pages'; @mkdir($localRoot, 0775, true);
            $restoredPages = 0;
            foreach (glob($staging . '/local-pages/*') ?: [] as $directory) {
                if (!is_dir($directory) || !ctype_digit(basename($directory))) continue;
                $id = (int) basename($directory); $target = $localRoot . '/' . $id; @mkdir($target, 0775, true);
                $count = 0;
                foreach (glob($directory . '/*') ?: [] as $page) if (is_file($page) && @copy($page, $target . '/' . basename($page))) { $count++; $restoredPages++; }
                $desired = 'source';
                foreach (($data['catalogs'] ?? []) as $catalog) if ((int) ($catalog['id'] ?? 0) === $id && ($catalog['reader_mode'] ?? '') === 'local') $desired = 'local';
                $this->pdo->prepare('UPDATE catalogs SET local_page_count=?, reader_mode=? WHERE id=?')->execute([$count, $count > 0 ? $desired : 'source', $id]);
            }
            $result['local_pages'] = $restoredPages;
            return $result;
        } finally {
            if (!$closed) $zip->close();
            $this->removeTree($staging);
        }
    }

    private function addStoredFile(ZipArchive $zip, string $file, string $name): void
    {
        if (!$zip->addFile($file, $name)) throw new RuntimeException('无法写入备份文件：' . $name);
        $zip->setCompressionName($name, ZipArchive::CM_STORE);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($directory);
    }

    public function import(array $data): array
    {
        if (($data['format'] ?? '') !== 'lezhai-brochure-data' || (int) ($data['version'] ?? 0) !== 1) {
            throw new RuntimeException('不是有效的乐宅.Life 图册数据文件。');
        }
        $categories = $data['categories'] ?? null;
        $catalogs = $data['catalogs'] ?? null;
        $views = $data['daily_views'] ?? [];
        $tutorials=$data['tutorials']??[]; $tutorialMedia=$data['tutorial_media']??[];
        $articles=$data['articles']??[];
        $articleMonthlyViews=$data['article_monthly_views']??[];
        if (!is_array($categories) || !is_array($catalogs) || !is_array($views) || !is_array($tutorials) || !is_array($tutorialMedia) || !is_array($articles) || !is_array($articleMonthlyViews) || count($categories) > 1000 || count($catalogs) > 10000 || count($views) > 500000 || count($tutorials)>10000 || count($tutorialMedia)>50000 || count($articles)>10000 || count($articleMonthlyViews)>500000) {
            throw new RuntimeException('数据文件结构或记录数量不正确。');
        }

        $categoryIds = [];
        foreach ($categories as $category) {
            $id = (int) ($category['id'] ?? 0);
            if ($id < 1 || trim((string) ($category['name'] ?? '')) === '' || trim((string) ($category['slug'] ?? '')) === '') {
                throw new RuntimeException('分类数据不完整。');
            }
            $categoryIds[$id] = true;
        }
        foreach ($catalogs as $catalog) {
            if ((int) ($catalog['id'] ?? 0) < 1 || !isset($categoryIds[(int) ($catalog['category_id'] ?? 0)])) {
                throw new RuntimeException('图册数据引用了不存在的分类。');
            }
            if (!in_array((string) ($catalog['source_type'] ?? ''), ['yunzhan365', 'goootu', 'flbook'], true)) {
                throw new RuntimeException('数据文件含有不支持的图册来源。');
            }
            if (!is_array($catalog['page_manifest'] ?? null)) {
                throw new RuntimeException('图册页面清单格式不正确。');
            }
        }
        $catalogIds = array_fill_keys(array_map(static fn (array $catalog): int => (int) $catalog['id'], $catalogs), true);
        foreach ($views as $view) {
            if (!isset($catalogIds[(int) ($view['catalog_id'] ?? 0)]) || !preg_match('/^[a-f0-9]{64}$/', (string) ($view['visitor_hash'] ?? ''))) {
                throw new RuntimeException('匿名浏览记录格式不正确。');
            }
        }
        $articleIds=array_fill_keys(array_map(static fn(array $article):int=>(int)($article['id']??0),$articles),true);
        foreach($articleMonthlyViews as $view){
            if(!isset($articleIds[(int)($view['article_id']??0)])||!preg_match('/^\d{4}-\d{2}-01$/',(string)($view['viewed_month']??''))||(int)($view['view_count']??-1)<0)throw new RuntimeException('文章热点记录格式不正确。');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM article_monthly_views; DELETE FROM articles; DELETE FROM tutorial_media; DELETE FROM tutorials; DELETE FROM catalog_daily_views; DELETE FROM catalogs; DELETE FROM categories');
            $categoryStatement = $this->pdo->prepare('INSERT INTO categories(id,name,slug,sort_order,is_active) VALUES(?,?,?,?,?)');
            foreach ($categories as $category) {
                $categoryStatement->execute([
                    (int) $category['id'], trim((string) $category['name']), trim((string) $category['slug']),
                    (int) ($category['sort_order'] ?? 0), $this->boolean($category['is_active'] ?? true),
                ]);
            }
            $catalogStatement = $this->pdo->prepare(
                'INSERT INTO catalogs(id,category_id,source_url,source_type,name,description,cover_path,cover_source_url,page_manifest,pdf_url,manual_priority,is_active,parse_status,parse_error,view_count,parsed_at,reader_mode,local_page_count,download_cache)
                 VALUES(?,?,?,?,?,?,?,?,?::jsonb,?,?,?,?,?,?,?,?,0,\'\')'
            );
            foreach ($catalogs as $catalog) {
                $catalogStatement->execute([
                    (int) $catalog['id'], (int) $catalog['category_id'], trim((string) ($catalog['source_url'] ?? '')),
                    (string) $catalog['source_type'], trim((string) ($catalog['name'] ?? '')), (string) ($catalog['description'] ?? ''),
                    (string) ($catalog['cover_path'] ?? ''), (string) ($catalog['cover_source_url'] ?? ''),
                    json_encode($catalog['page_manifest'], JSON_UNESCAPED_SLASHES), $catalog['pdf_url'] ?? null,
                    (int) ($catalog['manual_priority'] ?? 0), $this->boolean($catalog['is_active'] ?? true),
                    (string) ($catalog['parse_status'] ?? 'ok'), (string) ($catalog['parse_error'] ?? ''),
                    max(0, (int) ($catalog['view_count'] ?? 0)), $catalog['parsed_at'] ?? null, 'source',
                ]);
            }
            $viewStatement = $this->pdo->prepare('INSERT INTO catalog_daily_views(catalog_id,viewed_on,visitor_hash,created_at) VALUES(?,?,?,?)');
            foreach ($views as $view) {
                $viewStatement->execute([(int) $view['catalog_id'], (string) $view['viewed_on'], (string) $view['visitor_hash'], $view['created_at'] ?? date(DATE_ATOM)]);
            }
            $tutorialStatement=$this->pdo->prepare('INSERT INTO tutorials(id,title,description,body,cover_path,manual_priority,is_active) VALUES(?,?,?,?,?,?,?)');
            foreach($tutorials as $tutorial)$tutorialStatement->execute([(int)$tutorial['id'],trim((string)$tutorial['title']),(string)($tutorial['description']??''),(string)($tutorial['body']??''),(string)($tutorial['cover_path']??''),(int)($tutorial['manual_priority']??0),$this->boolean($tutorial['is_active']??true)]);
            $mediaStatement=$this->pdo->prepare('INSERT INTO tutorial_media(id,tutorial_id,media_type,source_type,title,url,file_path,mime_type,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');
            foreach($tutorialMedia as $media)$mediaStatement->execute([(int)$media['id'],(int)$media['tutorial_id'],(string)$media['media_type'],(string)$media['source_type'],(string)($media['title']??''),(string)($media['url']??''),(string)($media['file_path']??''),(string)($media['mime_type']??''),(int)($media['sort_order']??0)]);
            $articleStatement=$this->pdo->prepare('INSERT INTO articles(id,title,slug,excerpt,body_html,cover_path,seo_title,seo_keywords,meta_description,status,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($articles as $article)$articleStatement->execute([(int)$article['id'],(string)$article['title'],(string)$article['slug'],(string)($article['excerpt']??''),(string)($article['body_html']??''),(string)($article['cover_path']??''),(string)($article['seo_title']??''),(string)($article['seo_keywords']??''),(string)($article['meta_description']??''),($article['status']??'draft')==='published'?'published':'draft',$article['published_at']??null,$article['created_at']??date(DATE_ATOM),$article['updated_at']??date(DATE_ATOM)]);
            $articleViewStatement=$this->pdo->prepare('INSERT INTO article_monthly_views(article_id,viewed_month,view_count,updated_at) VALUES(?,?,?,?)');
            foreach($articleMonthlyViews as $view)$articleViewStatement->execute([(int)$view['article_id'],(string)$view['viewed_month'],(int)$view['view_count'],$view['updated_at']??date(DATE_ATOM)]);
            $this->pdo->exec("SELECT setval(pg_get_serial_sequence('categories','id'), COALESCE((SELECT MAX(id) FROM categories),1), EXISTS(SELECT 1 FROM categories)); SELECT setval(pg_get_serial_sequence('catalogs','id'), COALESCE((SELECT MAX(id) FROM catalogs),1), EXISTS(SELECT 1 FROM catalogs)); SELECT setval(pg_get_serial_sequence('tutorials','id'), COALESCE((SELECT MAX(id) FROM tutorials),1), EXISTS(SELECT 1 FROM tutorials)); SELECT setval(pg_get_serial_sequence('tutorial_media','id'), COALESCE((SELECT MAX(id) FROM tutorial_media),1), EXISTS(SELECT 1 FROM tutorial_media)); SELECT setval(pg_get_serial_sequence('articles','id'), COALESCE((SELECT MAX(id) FROM articles),1), EXISTS(SELECT 1 FROM articles))");
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->clearLocalPages();
        return ['categories' => count($categories), 'catalogs' => count($catalogs), 'tutorials'=>count($tutorials), 'articles'=>count($articles)];
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private function clearLocalPages(): void
    {
        $root = dirname(__DIR__) . '/storage/local-pages';
        if (!is_dir($root)) return;
        foreach (glob($root . '/*') ?: [] as $directory) {
            if (!is_dir($directory)) continue;
            foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) @unlink($file);
            @rmdir($directory);
        }
    }
}
