<?php
declare(strict_types=1);

$password = getenv('PGPASSWORD');
if (!is_string($password) || $password === '') {
    fwrite(STDERR, "缺少 PostgreSQL 密码。\n");
    exit(1);
}
try {
    $port = (int) (getenv('PGPORT') ?: 55432);
    $pdo = null;
    $errors = [];
    foreach (['127.0.0.1', '::1', 'localhost'] as $host) {
        try {
            $pdo = new PDO("pgsql:host={$host};port={$port};dbname=postgres;connect_timeout=2", 'postgres', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if (isset($argv[1])) file_put_contents($argv[1], $host);
            break;
        } catch (Throwable $error) {
            $errors[] = $host . '：' . $error->getMessage();
        }
    }
    if (!$pdo instanceof PDO) throw new RuntimeException(implode("\n", $errors));
    $exists = (bool) $pdo->query("SELECT 1 FROM pg_database WHERE datname='lezhai_brochure'")->fetchColumn();
    if (!$exists) {
        $pdo->exec('CREATE DATABASE lezhai_brochure ENCODING \'UTF8\'');
        echo "数据库已创建。\n";
    } else {
        echo "数据库已存在。\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "数据库检查失败：{$error->getMessage()}\n");
    exit(1);
}
