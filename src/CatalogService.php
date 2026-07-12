<?php
declare(strict_types=1);

namespace Lezhai;

use PDO;
use RuntimeException;
use ZipArchive;

final class CatalogService
{
    private readonly PDO $pdo;
    private readonly CatalogParser $parser;
    private readonly HttpClient $http;

    public function __construct(?PDO $pdo = null, ?CatalogParser $parser = null, ?HttpClient $http = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->parser = $parser ?? new CatalogParser();
        $this->http = $http ?? new HttpClient();
    }

    public function categories(bool $admin = false): array
    {
        $sql = 'SELECT c.*, COUNT(cat.id) FILTER (WHERE cat.is_active) AS catalog_count FROM categories c LEFT JOIN catalogs cat ON cat.category_id=c.id';
        if (!$admin) {
            $sql .= ' WHERE c.is_active';
        }
        $sql .= ' GROUP BY c.id ORDER BY c.sort_order DESC, c.id';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function catalogs(?int $categoryId = null, bool $admin = false): array
    {
        $where = $admin ? 'TRUE' : 'c.is_active AND cat.is_active';
        $params = [];
        if ($categoryId) {
            $where .= ' AND cat.category_id=?';
            $params[] = $categoryId;
        }
        $stmt = $this->pdo->prepare("SELECT cat.*, c.name AS category_name FROM catalogs cat JOIN categories c ON c.id=cat.category_id WHERE {$where} ORDER BY cat.manual_priority DESC, cat.view_count DESC, cat.id DESC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function popular(?int $categoryId): array
    {
        $where = 'c.is_active AND cat.is_active';
        $params = [];
        if ($categoryId) {
            $where .= ' AND cat.category_id=?';
            $params[] = $categoryId;
        }
        $stmt = $this->pdo->prepare("SELECT cat.*, c.name AS category_name FROM catalogs cat JOIN categories c ON c.id=cat.category_id WHERE {$where} ORDER BY cat.view_count DESC, cat.manual_priority DESC, cat.id DESC LIMIT 5");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(int $id, bool $admin = false): ?array
    {
        $sql = 'SELECT cat.*, c.name AS category_name FROM catalogs cat JOIN categories c ON c.id=cat.category_id WHERE cat.id=?';
        if (!$admin) {
            $sql .= ' AND cat.is_active AND c.is_active';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function preview(string $url): array
    {
        return $this->parser->parse($url);
    }

    public function create(array $input): int
    {
        $parsed = $this->parser->parse((string) $input['source_url']);
        $cover = $this->cacheCover($parsed['cover_url']);
        $stmt = $this->pdo->prepare(
            'INSERT INTO catalogs(category_id, source_url, source_type, name, description, cover_path, cover_source_url, page_manifest, pdf_url, manual_priority, is_active, parse_status, parsed_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,\'ok\',NOW()) RETURNING id'
        );
        $stmt->execute([
            (int) $input['category_id'], $parsed['source_url'], $parsed['source_type'],
            trim((string) ($input['name'] ?: $parsed['title'])), trim((string) ($input['description'] ?? '')),
            $cover, $parsed['cover_url'], json_encode($parsed['pages'], JSON_UNESCAPED_SLASHES), $parsed['pdf_url'],
            (int) ($input['manual_priority'] ?? 0), isset($input['is_active']),
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $input, ?array $upload = null): void
    {
        $catalog = $this->find($id, true);
        if (!$catalog) {
            throw new RuntimeException('图册不存在。');
        }
        $cover = $catalog['cover_path'];
        if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $cover = $this->saveUpload($upload, $id);
        }
        $readerMode = in_array(($input['reader_mode'] ?? 'source'), ['source', 'local'], true) ? $input['reader_mode'] : 'source';
        if ($readerMode === 'local' && (int) $catalog['local_page_count'] < 1) {
            throw new RuntimeException('请先生成本地高清图片，再选择本地图片阅读。');
        }
        $this->pdo->prepare('UPDATE catalogs SET category_id=?, name=?, description=?, manual_priority=?, is_active=?, cover_path=?, reader_mode=?, updated_at=NOW() WHERE id=?')
            ->execute([(int) $input['category_id'], trim((string) $input['name']), trim((string) ($input['description'] ?? '')), (int) ($input['manual_priority'] ?? 0), isset($input['is_active']), $cover, $readerMode, $id]);
    }

    public function reparse(int $id): void
    {
        $catalog = $this->find($id, true);
        if (!$catalog) {
            throw new RuntimeException('图册不存在。');
        }
        try {
            $parsed = $this->parser->parse($catalog['source_url']);
            $cover = $this->cacheCover($parsed['cover_url']);
            $this->invalidateDownload($catalog);
            $this->invalidateLocalPages($id);
            $this->pdo->prepare('UPDATE catalogs SET source_url=?, source_type=?, cover_path=?, cover_source_url=?, page_manifest=?, pdf_url=?, name=CASE WHEN BTRIM(name)=\'\' THEN ? ELSE name END, description=CASE WHEN BTRIM(description)=\'\' THEN ? ELSE description END, parse_status=\'ok\', parse_error=\'\', parsed_at=NOW(), updated_at=NOW() WHERE id=?')
                ->execute([$parsed['source_url'], $parsed['source_type'], $cover, $parsed['cover_url'], json_encode($parsed['pages'], JSON_UNESCAPED_SLASHES), $parsed['pdf_url'], $parsed['title'], $parsed['description'], $id]);
        } catch (\Throwable $e) {
            $this->pdo->prepare('UPDATE catalogs SET parse_status=\'error\', parse_error=?, updated_at=NOW() WHERE id=?')->execute([$e->getMessage(), $id]);
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $catalog = $this->find($id, true);
        if ($catalog) {
            $this->invalidateDownload($catalog);
            $this->invalidateLocalPages($id);
            $this->pdo->prepare('DELETE FROM catalogs WHERE id=?')->execute([$id]);
        }
    }

    public function recordView(int $id, string $visitor): bool
    {
        $hash = hash_hmac('sha256', $visitor, Config::get('APP_SECRET'));
        $this->pdo->beginTransaction();
        $stmt = $this->pdo->prepare('INSERT INTO catalog_daily_views(catalog_id, viewed_on, visitor_hash) VALUES(?, CURRENT_DATE, ?) ON CONFLICT DO NOTHING');
        $stmt->execute([$id, $hash]);
        $inserted = $stmt->rowCount() === 1;
        if ($inserted) {
            $this->pdo->prepare('UPDATE catalogs SET view_count=view_count+1 WHERE id=?')->execute([$id]);
        }
        $this->pdo->commit();
        return $inserted;
    }

    public function saveCategory(array $input, ?int $id = null): void
    {
        $name = trim((string) $input['name']);
        if ($name === '') {
            throw new RuntimeException('分类名称不能为空。');
        }
        $slug = trim((string) ($input['slug'] ?? '')) ?: 'category-' . substr(hash('sha256', $name), 0, 10);
        $values = [$name, $slug, (int) ($input['sort_order'] ?? 0), isset($input['is_active'])];
        if ($id) {
            $values[] = $id;
            $this->pdo->prepare('UPDATE categories SET name=?, slug=?, sort_order=?, is_active=?, updated_at=NOW() WHERE id=?')->execute($values);
        } else {
            $this->pdo->prepare('INSERT INTO categories(name,slug,sort_order,is_active) VALUES(?,?,?,?)')->execute($values);
        }
    }

    public function deleteCategory(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM catalogs WHERE category_id=?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('分类下仍有图册，不能删除。');
        }
        $this->pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$id]);
    }

    public function buildDownload(int $id): string
    {
        $catalog = $this->find($id, true);
        if (!$catalog) {
            throw new RuntimeException('图册不存在。');
        }
        $downloadDir = dirname(__DIR__) . '/storage/downloads';
        $runtimeDir = dirname(__DIR__) . '/storage/runtime';
        @mkdir($downloadDir, 0775, true);
        @mkdir($runtimeDir, 0775, true);
        $safe = preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $catalog['name']) ?: 'catalog-' . $id;
        if ($catalog['pdf_url']) {
            $target = $downloadDir . '/' . $safe . '-' . $id . '.pdf';
            if (!is_file($target)) {
                $this->http->download($catalog['pdf_url'], $target . '.tmp');
                rename($target . '.tmp', $target);
            }
            return $target;
        }
        $pages = json_decode($catalog['page_manifest'], true) ?: [];
        if ($pages === []) {
            throw new RuntimeException('此图册没有可下载的页面清单。');
        }
        $target = $downloadDir . '/' . $safe . '-' . $id . '.zip';
        if (is_file($target)) {
            return $target;
        }
        $renderDir = null;
        $renderedPages = [];
        if ($catalog['source_type'] === 'yunzhan365' && $this->requiresBrowserRender($pages)) {
            $renderDir = $runtimeDir . '/yunzhan-' . $id . '-' . bin2hex(random_bytes(4));
            $renderedPages = $this->renderYunzhanPages($catalog['source_url'], $renderDir, count($pages));
        }
        $tmpZip = $target . '.tmp';
        @unlink($tmpZip);
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            if ($renderDir !== null) {
                $this->removeRenderDirectory($renderDir);
            }
            throw new RuntimeException('无法创建 ZIP 文件。');
        }
        try {
            $started = microtime(true);
            foreach ($pages as $index => $url) {
                if (microtime(true) - $started > 240) {
                    throw new RuntimeException('生成下载包超过 4 分钟，已停止。请检查网络后重试。');
                }
                if ($renderedPages !== []) {
                    $pageFile = $renderedPages[$index] ?? '';
                    if ($pageFile === '' || !is_file($pageFile) || !$zip->addFile($pageFile, sprintf('%04d.png', $index + 1))) {
                        throw new RuntimeException('浏览器还原的第 ' . ($index + 1) . ' 页无法写入 ZIP。');
                    }
                    continue;
                }
                $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
                $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
                $pageFile = $runtimeDir . '/page-' . $id . '-' . ($index + 1) . '.' . $extension;
                try {
                    $this->http->download($url, $pageFile);
                } catch (\Throwable $e) {
                    throw new RuntimeException('第 ' . ($index + 1) . ' 页下载失败：' . $url . '；' . $e->getMessage(), 0, $e);
                }
                $zip->addFile($pageFile, sprintf('%04d.%s', $index + 1, $extension));
            }
            $zip->close();
            foreach (glob($runtimeDir . '/page-' . $id . '-*') ?: [] as $file) {
                @unlink($file);
            }
            if ($renderDir !== null) {
                $this->removeRenderDirectory($renderDir);
            }
            rename($tmpZip, $target);
            $this->pdo->prepare('UPDATE catalogs SET download_cache=? WHERE id=?')->execute([$target, $id]);
            return $target;
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($tmpZip);
            if ($renderDir !== null) {
                $this->removeRenderDirectory($renderDir);
            }
            foreach (glob($runtimeDir . '/page-' . $id . '-*') ?: [] as $file) {
                @unlink($file);
            }
            throw new RuntimeException('下载包生成失败：' . $e->getMessage(), 0, $e);
        }
    }

    public function buildLocalPages(int $id): int
    {
        $catalog = $this->find($id, true);
        if (!$catalog) throw new RuntimeException('图册不存在。');
        $download = $this->buildDownload($id);
        if (!str_ends_with(strtolower($download), '.zip')) {
            throw new RuntimeException('该图册只能下载 PDF，暂不能生成本地图片阅读。');
        }

        $root = dirname(__DIR__) . '/storage/local-pages';
        $target = $root . '/' . $id;
        $staging = $root . '/.' . $id . '-' . bin2hex(random_bytes(4));
        @mkdir($staging, 0775, true);
        $zip = new ZipArchive();
        if ($zip->open($download) !== true) throw new RuntimeException('下载包无法打开。');
        $count = 0;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || !preg_match('~^\d{4}\.(?:jpg|jpeg|png|webp)$~i', $name)) continue;
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) throw new RuntimeException('无法读取本地图片：' . $name);
                $output = fopen($staging . '/' . $name, 'wb');
                if (!is_resource($output)) { fclose($stream); throw new RuntimeException('无法保存本地图片。'); }
                stream_copy_to_stream($stream, $output);
                fclose($stream); fclose($output);
                $size = @getimagesize($staging . '/' . $name);
                if (!$size) throw new RuntimeException('下载包内含有无效图片：' . $name);
                $count++;
            }
        } catch (\Throwable $e) {
            $this->clearDirectory($staging);
            throw $e;
        } finally {
            $zip->close();
        }
        if ($count < 1) {
            $this->clearDirectory($staging);
            throw new RuntimeException('下载包中没有可用于阅读的页面图片。');
        }
        $this->clearDirectory($target);
        @mkdir($root, 0775, true);
        if (!@rename($staging, $target)) {
            $this->clearDirectory($staging);
            throw new RuntimeException('无法启用本地图片目录。');
        }
        $this->pdo->prepare('UPDATE catalogs SET local_page_count=?, updated_at=NOW() WHERE id=?')->execute([$count, $id]);
        return $count;
    }

    public function localPages(int $id): array
    {
        $files = glob(dirname(__DIR__) . '/storage/local-pages/' . $id . '/*') ?: [];
        $files = array_values(array_filter($files, static fn (string $file): bool => is_file($file) && (bool) preg_match('~\.(?:jpg|jpeg|png|webp)$~i', $file)));
        sort($files, SORT_STRING);
        return $files;
    }

    public function localPageFile(int $id, string $name): ?string
    {
        if (!preg_match('~^\d{4}\.(?:jpg|jpeg|png|webp)$~i', $name)) return null;
        $file = dirname(__DIR__) . '/storage/local-pages/' . $id . '/' . $name;
        return is_file($file) ? $file : null;
    }

    private function invalidateLocalPages(int $id): void
    {
        $this->clearDirectory(dirname(__DIR__) . '/storage/local-pages/' . $id);
        $this->pdo->prepare('UPDATE catalogs SET local_page_count=0, reader_mode=\'source\' WHERE id=?')->execute([$id]);
    }

    private function clearDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (glob($directory . '/*') ?: [] as $file) if (is_file($file)) @unlink($file);
        @rmdir($directory);
    }

    private function requiresBrowserRender(array $pages): bool
    {
        foreach ($pages as $page) {
            $page = (string) $page;
            if (str_starts_with($page, 'browser-render://') || preg_match('~\.zip(?:\?|$)~i', $page)) {
                return true;
            }
        }
        return false;
    }

    private function renderYunzhanPages(string $url, string $outputDir, int $expected): array
    {
        $node = Config::get('NODE_BINARY', 'node');
        $script = dirname(__DIR__) . '/scripts/yunzhan-export.js';
        $command = [$node, $script, $url, $outputDir, (string) $expected, Config::get('BROWSER_EXECUTABLE')];
        @mkdir($outputDir, 0775, true);
        $pipes = [];
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动云展网高清页面还原器。');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;
        $status = ['exitcode' => -1];
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) - $started > 240) {
                proc_terminate($process);
                $timedOut = true;
                break;
            }
            usleep(100000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = $status['exitcode'] ?? -1;
        $code = proc_close($process);
        if ($code === -1) $code = $exitCode;

        if ($timedOut) {
            $this->removeRenderDirectory($outputDir);
            throw new RuntimeException('云展网高清页面还原超过 4 分钟，已停止本次任务。');
        }
        $files = glob($outputDir . '/*.png') ?: [];
        sort($files, SORT_STRING);
        if ($code !== 0 || count($files) !== $expected) {
            $this->removeRenderDirectory($outputDir);
            $detail = trim($stderr) ?: trim($stdout);
            throw new RuntimeException('云展网高清页面还原失败（' . count($files) . '/' . $expected . ' 页）：' . $detail);
        }
        foreach ($files as $index => $file) {
            $size = @getimagesize($file);
            if (!$size || $size[0] < 1000 || $size[1] < 1000) {
                $this->removeRenderDirectory($outputDir);
                throw new RuntimeException('云展网第 ' . ($index + 1) . ' 页还原尺寸异常。');
            }
        }
        return $files;
    }

    private function removeRenderDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        if (is_dir($directory)) @rmdir($directory);
    }

    private function cacheCover(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $dir = dirname(__DIR__) . '/public/uploads/covers';
        @mkdir($dir, 0775, true);
        $filename = hash('sha256', $url) . '.img';
        $target = $dir . '/' . $filename;
        if (!is_file($target)) {
            $this->http->download($url, $target . '.tmp');
            rename($target . '.tmp', $target);
        }
        return '/uploads/covers/' . $filename;
    }

    private function saveUpload(array $upload, int $id): string
    {
        if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('封面上传失败。');
        $temporary=(string)($upload['tmp_name']??'');$size=(int)($upload['size']??0);
        if($size<1||$size>10*1024*1024||!is_uploaded_file($temporary))throw new RuntimeException('封面文件大小或来源无效。');
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$type])) {
            throw new RuntimeException('封面只支持 JPG、PNG 或 WebP。');
        }
        $dir = dirname(__DIR__) . '/public/uploads/covers';
        @mkdir($dir, 0775, true);
        $filename = 'manual-' . $id . '-' . time() . '.' . $extensions[$type];
        if (!move_uploaded_file($temporary, $dir . '/' . $filename)) {
            throw new RuntimeException('封面上传失败。');
        }
        return '/uploads/covers/' . $filename;
    }

    private function invalidateDownload(array $catalog): void
    {
        if (!empty($catalog['download_cache']) && is_file($catalog['download_cache'])) {
            @unlink($catalog['download_cache']);
        }
        $this->pdo->prepare('UPDATE catalogs SET download_cache=\'\' WHERE id=?')->execute([$catalog['id']]);
    }
}
