<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Lezhai\Database::connection();
$pdo->exec("INSERT INTO categories(name, slug, sort_order, is_active) VALUES ('待整理', 'pending', 100, TRUE) ON CONFLICT(slug) DO NOTHING");
$categoryId = (int) $pdo->query("SELECT id FROM categories WHERE slug='pending'")->fetchColumn();
$urls = [
    'https://book.yunzhan365.com/wxfu/egep/mobile/index.html',
    'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=51007',
    'https://book.yunzhan365.com/glos/pitj/mobile/index.html',
    'https://book.yunzhan365.com/pwww/tzyw/mobile/index.html',
    'https://book.yunzhan365.com/wxfu/pmqy/mobile/index.html',
    'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=29389',
    'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=41867',
    'https://flbook.com.cn/c/DUiKDGRoCO',
    'https://book.yunzhan365.com/lbbc/uvjq/mobile/index.html',
    'https://flbook.com.cn/c/pjVkNtD843',
];
$service = new Lezhai\CatalogService($pdo);
$failed = 0;
foreach ($urls as $index => $url) {
    $exists = $pdo->prepare('SELECT 1 FROM catalogs WHERE source_url=?');
    $exists->execute([$url]);
    if ($exists->fetchColumn()) {
        echo '已存在，跳过 ' . ($index + 1) . '/' . count($urls) . "。\n";
        continue;
    }
    try {
        echo '正在解析 ' . ($index + 1) . '/' . count($urls) . "...\n";
        $service->create(['category_id' => $categoryId, 'source_url' => $url, 'name' => '', 'description' => '', 'manual_priority' => 0, 'is_active' => '1']);
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "导入失败：{$url}\n{$e->getMessage()}\n");
    }
}
echo "初始图册导入完成。\n";
exit($failed === 0 ? 0 : 1);
