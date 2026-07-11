<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$id = (int)($argv[1] ?? 0);
if ($id < 1) { fwrite(STDERR, "请提供图册编号。\n"); exit(1); }
$service = new Lezhai\CatalogService(Lezhai\Database::connection());
$catalog = $service->find($id, true);
if (!$catalog) { fwrite(STDERR, "图册不存在。\n"); exit(1); }
$expected = count(json_decode($catalog['page_manifest'], true) ?: []);
$path = $service->buildDownload($id);
if (str_ends_with($path, '.pdf')) {
    $header = file_get_contents($path, false, null, 0, 5);
    if ($header !== '%PDF-') throw new RuntimeException('PDF 文件头不正确。');
    echo "OK {$catalog['source_type']} PDF bytes=" . filesize($path) . "\n";
    exit(0);
}
$zip = new ZipArchive();
if ($zip->open($path) !== true) throw new RuntimeException('ZIP 无法打开。');
if ($zip->numFiles !== $expected) throw new RuntimeException("ZIP 页数不一致：{$zip->numFiles}/{$expected}");
$first = $zip->getFromIndex(0);
$lastName = $zip->getNameIndex($zip->numFiles - 1);
$size = is_string($first) ? @getimagesizefromstring($first) : false;
if (!$size) throw new RuntimeException('ZIP 第一页不是有效图片。');
$zip->close();
echo "OK {$catalog['source_type']} pages={$expected} first={$size[0]}x{$size[1]} last={$lastName} bytes=" . filesize($path) . "\n";
