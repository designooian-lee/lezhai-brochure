<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
if ($sql === false) {
    fwrite(STDERR, "无法读取数据库结构。\n");
    exit(1);
}
Lezhai\Database::connection()->exec($sql);
echo "数据库迁移完成。\n";

