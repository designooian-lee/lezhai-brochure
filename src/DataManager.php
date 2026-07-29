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
        $siteSettings = (new SiteSettingsService($this->pdo))->get();
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
            'site_settings' => $siteSettings,
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
                    $name = 'covers/' . basename($file);
                    if (is_file($file) && $this->isAllowedBackupPath($name)) $this->addStoredFile($zip, $file, $name);
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
                if (str_starts_with($path,'/uploads/tutorials/')) { $file=dirname(__DIR__).'/public'.$path; $name='tutorials/'.basename($file); if(is_file($file)&&$this->isAllowedBackupPath($name))$this->addStoredFile($zip,$file,$name); }
            }
            foreach ($this->pdo->query('SELECT cover_path FROM articles')->fetchAll() as $asset) {
                $path=(string)$asset['cover_path']; if(str_starts_with($path,'/uploads/articles/')){$file=dirname(__DIR__).'/public'.$path;$name='articles/'.basename($file);if(is_file($file)&&$this->isAllowedBackupPath($name))$this->addStoredFile($zip,$file,$name);}
            }
            foreach(glob(dirname(__DIR__).'/public/uploads/articles/*')?:[] as $file){$name='articles/'.basename($file);if(is_file($file)&&$this->isAllowedBackupPath($name)&&$zip->locateName($name)===false)$this->addStoredFile($zip,$file,$name);}
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
        if ($zip->numFiles > 100000) { $zip->close(); throw new RuntimeException('备份文件条目过多。'); }
        $jsonStat = $zip->statName('data.json');
        $jsonSize = is_array($jsonStat) ? (int) ($jsonStat['size'] ?? 0) : 0;
        if ($jsonSize < 1 || $jsonSize > 20 * 1024 * 1024) {
            $zip->close();
            throw new RuntimeException('备份中的 data.json 大小无效或超过 20MB。');
        }
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
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;
                if ($name === 'data.json' || str_ends_with($name, '/')) continue;
                if ($size < 1) throw new RuntimeException('备份包含空文件：' . $name);
                if (!$this->isAllowedBackupPath($name)) {
                    throw new RuntimeException('备份 ZIP 含有不允许的文件路径。');
                }
                $total += $size;
                if ($total > 5 * 1024 * 1024 * 1024) throw new RuntimeException('备份解压后超过 5GB。');
                $target = $staging . '/' . str_replace('/', DIRECTORY_SEPARATOR, $name);
                @mkdir(dirname($target), 0775, true);
                $input = $zip->getStream($name); $output = fopen($target, 'wb');
                if (!is_resource($input) || !is_resource($output)) throw new RuntimeException('无法读取备份文件：' . $name);
                $copied = stream_copy_to_stream($input, $output, $size + 1); fclose($input); fclose($output);
                if ($copied !== $size) throw new RuntimeException('备份文件解压大小不一致：' . $name);
                $this->assertExtractedAsset($target, $name);
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

    private function isAllowedBackupPath(string $name): bool
    {
        return (bool) preg_match(
            '~^(?:'
            . 'covers/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|img)'
            . '|articles/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif)'
            . '|tutorials/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|mp4|webm|pdf|doc|docx)'
            . '|local-pages/\d+/\d{4}\.(?:jpe?g|png|webp)'
            . ')$~i',
            $name
        );
    }

    private function assertExtractedAsset(string $file, string $name): void
    {
        if (!preg_match('~\.(?:jpe?g|png|webp|gif|img)$~i', $name)) return;
        $info = @getimagesize($file);
        if (!$info || !in_array((string) ($info['mime'] ?? ''), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new RuntimeException('备份包含无效图片：' . $name);
        }
    }

    private function isAllowedCatalogUrl(string $url, bool $resource, ?string $sourceType = null): bool
    {
        if (!$this->isExternalUrl($url, true)) return false;
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = in_array($host, ['book.yunzhan365.com', 'book.goootu.com', 'flbook.com.cn'], true);
        if ($resource) {
            $allowed = $allowed || (bool) preg_match('~^img\d*\.flbook\.com\.cn$~', $host);
        }
        if (!$allowed) return false;
        return match ($sourceType) {
            'yunzhan365' => $host === 'book.yunzhan365.com',
            'goootu' => $host === 'book.goootu.com',
            'flbook' => $host === 'flbook.com.cn',
            default => true,
        };
    }

    private function isExternalUrl(string $url, bool $standardPortsOnly = false): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return false;
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        return isset($parts['host'])
            && in_array($scheme, ['http', 'https'], true)
            && (!$standardPortsOnly || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80));
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
        $siteSettings=(new SiteSettingsService($this->pdo))->normalize(is_array($data['site_settings']??null)?$data['site_settings']:[]);
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
            $sourceType = (string) ($catalog['source_type'] ?? '');
            if (!in_array($sourceType, ['yunzhan365', 'goootu', 'flbook'], true)) {
                throw new RuntimeException('数据文件含有不支持的图册来源。');
            }
            if (!$this->isAllowedCatalogUrl((string) ($catalog['source_url'] ?? ''), false, $sourceType)) {
                throw new RuntimeException('图册来源链接不安全或与来源类型不匹配。');
            }
            $pages = $catalog['page_manifest'] ?? null;
            if (!is_array($pages) || count($pages) > 2000) {
                throw new RuntimeException('图册页面清单格式不正确。');
            }
            foreach ($pages as $page) {
                $browserPage = $sourceType === 'yunzhan365' && is_string($page) && preg_match('~^browser-render://[1-9]\d*$~', $page);
                if (!$browserPage && (!is_string($page) || !$this->isAllowedCatalogUrl($page, true))) {
                    throw new RuntimeException('图册页面清单含有不安全链接。');
                }
            }
            $coverPath = (string) ($catalog['cover_path'] ?? '');
            if ($coverPath !== '' && !preg_match('~^/uploads/covers/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|img)$~i', $coverPath)) {
                throw new RuntimeException('图册封面路径不安全。');
            }
            foreach (['cover_source_url', 'pdf_url'] as $field) {
                $remote = (string) ($catalog[$field] ?? '');
                if ($remote !== '' && !$this->isAllowedCatalogUrl($remote, true)) {
                    throw new RuntimeException('图册资源链接不安全。');
                }
            }
        }
        $catalogIds = array_fill_keys(array_map(static fn (array $catalog): int => (int) $catalog['id'], $catalogs), true);
        foreach ($views as $view) {
            if (!isset($catalogIds[(int) ($view['catalog_id'] ?? 0)]) || !preg_match('/^[a-f0-9]{64}$/', (string) ($view['visitor_hash'] ?? ''))) {
                throw new RuntimeException('匿名浏览记录格式不正确。');
            }
        }
        $tutorialIds = [];
        foreach ($tutorials as $tutorial) {
            $tutorialId = (int) ($tutorial['id'] ?? 0);
            $coverPath = (string) ($tutorial['cover_path'] ?? '');
            if ($tutorialId < 1 || trim((string) ($tutorial['title'] ?? '')) === '' || isset($tutorialIds[$tutorialId])) {
                throw new RuntimeException('教程数据不完整或编号重复。');
            }
            if ($coverPath !== '' && !preg_match('~^/uploads/tutorials/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp)$~i', $coverPath)) {
                throw new RuntimeException('教程封面路径不安全。');
            }
            $tutorialIds[$tutorialId] = true;
        }
        $mediaIds = [];
        foreach ($tutorialMedia as $media) {
            $mediaId = (int) ($media['id'] ?? 0);
            $tutorialId = (int) ($media['tutorial_id'] ?? 0);
            $mediaType = (string) ($media['media_type'] ?? '');
            $sourceType = (string) ($media['source_type'] ?? '');
            if ($mediaId < 1 || isset($mediaIds[$mediaId]) || !isset($tutorialIds[$tutorialId])
                || !in_array($mediaType, ['video', 'document'], true)
                || !in_array($sourceType, ['external', 'upload'], true)) {
                throw new RuntimeException('教程附件数据不完整。');
            }
            if ($sourceType === 'external') {
                if (!$this->isExternalUrl((string) ($media['url'] ?? ''))) {
                    throw new RuntimeException('教程附件外链不安全。');
                }
            } else {
                $extensions = $mediaType === 'video' ? 'mp4|webm' : 'pdf|doc|docx';
                if (!preg_match('~^/uploads/tutorials/[A-Za-z0-9._-]+\.(?:' . $extensions . ')$~i', (string) ($media['file_path'] ?? ''))) {
                    throw new RuntimeException('教程附件文件路径不安全。');
                }
            }
            $mediaIds[$mediaId] = true;
        }
        $articleIds = [];
        $articleSanitizer = new ArticleService($this->pdo);
        foreach ($articles as &$article) {
            $articleId = (int) ($article['id'] ?? 0);
            $slug = (string) ($article['slug'] ?? '');
            $coverPath = (string) ($article['cover_path'] ?? '');
            if ($articleId < 1 || isset($articleIds[$articleId]) || trim((string) ($article['title'] ?? '')) === ''
                || !preg_match('~^[a-z0-9]+(?:-[a-z0-9]+)*$~', $slug)) {
                throw new RuntimeException('文章数据不完整或网址标识无效。');
            }
            if ($coverPath !== '' && !preg_match('~^/uploads/articles/[A-Za-z0-9._-]+\.(?:jpe?g|png|webp|gif)$~i', $coverPath)) {
                throw new RuntimeException('文章封面路径不安全。');
            }
            $article['body_html'] = $articleSanitizer->sanitizeHtml((string) ($article['body_html'] ?? ''));
            $articleIds[$articleId] = true;
        }
        unset($article);
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
            $this->pdo->prepare('INSERT INTO site_settings(id,icp_number,police_number,updated_at) VALUES(1,?,?,NOW()) ON CONFLICT(id) DO UPDATE SET icp_number=EXCLUDED.icp_number,police_number=EXCLUDED.police_number,updated_at=NOW()')->execute([$siteSettings['icp_number'],$siteSettings['police_number']]);
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
