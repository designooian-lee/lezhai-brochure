<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$failed = 0;
function check(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) { echo "[通过] {$message}\n"; $passed++; }
    else { fwrite(STDERR, "[失败] {$message}\n"); $failed++; }
}

$pdo = Lezhai\Database::connection();
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='catalogs'")->fetchColumn() === 1, '数据库结构已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name='catalogs' AND column_name IN ('reader_mode','local_page_count')")->fetchColumn() === 2, '本地阅读模式字段已创建');
check((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() >= 1, '至少有一个分类');
check((int)$pdo->query('SELECT COUNT(DISTINCT source_type) FROM catalogs')->fetchColumn() >= 3, '已导入云展网、goootu、FLBOOK 三类样本');

$parser = new Lezhai\CatalogParser();
try { $parser->parse('https://example.com/book'); check(false, '未知域名被拒绝'); } catch (Throwable $e) { check(str_contains($e->getMessage(), '暂不支持'), '未知域名被拒绝'); }
try { $parser->parse('https://book.yunzhan365.com/wxfu/urim/mobile/index.htmll'); check(false, '错误扩展名提供建议'); } catch (Throwable $e) { check(str_contains($e->getMessage(), '建议修正'), '错误扩展名提供建议'); }

$service = new Lezhai\CatalogService($pdo);
$catalog = $pdo->query('SELECT id FROM catalogs ORDER BY id LIMIT 1')->fetch();
if ($catalog) {
    $visitor = bin2hex(random_bytes(16));
    $before = (int)$pdo->query('SELECT view_count FROM catalogs WHERE id=' . (int)$catalog['id'])->fetchColumn();
    check($service->recordView((int)$catalog['id'], $visitor) === true, '首次浏览计入热度');
    check($service->recordView((int)$catalog['id'], $visitor) === false, '同设备当天不重复计数');
    $visitorHash = hash_hmac('sha256', $visitor, Lezhai\Config::get('APP_SECRET'));
    $cleanup = $pdo->prepare('DELETE FROM catalog_daily_views WHERE catalog_id=? AND visitor_hash=?');
    $cleanup->execute([(int)$catalog['id'], $visitorHash]);
    $pdo->prepare('UPDATE catalogs SET view_count=? WHERE id=?')->execute([$before, (int)$catalog['id']]);
}

class FakeImageHttpClient extends Lezhai\HttpClient {
    public function download(string $url, string $target): void {
        file_put_contents($target, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
$categoryId = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$sourceUrl = 'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=' . random_int(900000, 999999);
$insert = $pdo->prepare("INSERT INTO catalogs(category_id,source_url,source_type,name,description,page_manifest,is_active,parse_status) VALUES(?,?, 'goootu','ZIP 自动测试','',?::jsonb,FALSE,'ok') RETURNING id");
$insert->execute([$categoryId, $sourceUrl, json_encode([
    'http://book.goootu.com/UpLoads/Magazine/InsidePages/test/1500/1.jpg',
    'http://book.goootu.com/UpLoads/Magazine/InsidePages/test/1500/2.jpg',
    'http://book.goootu.com/UpLoads/Magazine/InsidePages/test/1500/3.jpg',
])]);
$downloadId = (int)$insert->fetchColumn();
try {
    $downloadService = new Lezhai\CatalogService($pdo, null, new FakeImageHttpClient());
    $zipPath = $downloadService->buildDownload($downloadId);
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath) === true;
    check($opened && $zip->numFiles === 3 && $zip->getNameIndex(0) === '0001.jpg' && $zip->getNameIndex(2) === '0003.jpg', 'ZIP 下载包按页码完整生成');
    if ($opened) $zip->close();
    $localCount = $downloadService->buildLocalPages($downloadId);
    $localPages = $downloadService->localPages($downloadId);
    check($localCount === 3 && count($localPages) === 3 && basename($localPages[0]) === '0001.jpg', '下载包可生成本地图片阅读');
    @unlink($zipPath);
} finally {
    $service->delete($downloadId);
}

$manager = new Lezhai\DataManager($pdo);
$export = $manager->export();
check(($export['format'] ?? '') === 'lezhai-brochure-data' && count($export['catalogs'] ?? []) >= 1, '数据管理可导出标准 JSON');
try { $manager->import(['format' => 'invalid']); check(false, '无效导入文件被拒绝'); }
catch (Throwable $e) { check(str_contains($e->getMessage(), '有效'), '无效导入文件被拒绝'); }

if (in_array('--live', $argv, true)) {
    foreach ([
        'https://book.yunzhan365.com/wxfu/egep/mobile/index.html' => 'yunzhan365',
        'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=51007' => 'goootu',
        'https://flbook.com.cn/c/DUiKDGRoCO' => 'flbook',
    ] as $url => $type) {
        try { $result=$parser->parse($url); check($result['source_type']===$type && count($result['pages'])>0, "{$type} 在线解析"); }
        catch (Throwable $e) { check(false, "{$type} 在线解析：{$e->getMessage()}"); }
    }
}

echo "\n通过 {$passed}，失败 {$failed}\n";
exit($failed === 0 ? 0 : 1);
