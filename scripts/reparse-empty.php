<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$pdo = Lezhai\Database::connection();
$service = new Lezhai\CatalogService($pdo);
$ids = $pdo->query("SELECT id FROM catalogs WHERE BTRIM(name)='' OR parse_status<>'ok' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $index => $id) {
    echo '正在刷新 ' . ($index + 1) . '/' . count($ids) . "...\n";
    $service->reparse((int)$id);
}
echo "空白资料刷新完成。\n";
