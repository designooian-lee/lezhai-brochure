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
        return [
            'format' => 'lezhai-brochure-data',
            'version' => 1,
            'exported_at' => date(DATE_ATOM),
            'categories' => $categories,
            'catalogs' => $catalogs,
            'daily_views' => $views,
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
                if (!preg_match('~^(covers/[A-Za-z0-9._-]+|local-pages/\d+/\d{4}\.(?:jpg|jpeg|png|webp))$~i', $name)) {
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
        if (!is_array($categories) || !is_array($catalogs) || !is_array($views) || count($categories) > 1000 || count($catalogs) > 10000 || count($views) > 500000) {
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

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM catalog_daily_views; DELETE FROM catalogs; DELETE FROM categories');
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
            $this->pdo->exec("SELECT setval(pg_get_serial_sequence('categories','id'), COALESCE((SELECT MAX(id) FROM categories),1), EXISTS(SELECT 1 FROM categories)); SELECT setval(pg_get_serial_sequence('catalogs','id'), COALESCE((SELECT MAX(id) FROM catalogs),1), EXISTS(SELECT 1 FROM catalogs))");
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->clearLocalPages();
        return ['categories' => count($categories), 'catalogs' => count($catalogs)];
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
