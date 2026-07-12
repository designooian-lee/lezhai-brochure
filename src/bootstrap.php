<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Lezhai\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Lezhai\Config::load(dirname(__DIR__) . '/.env');

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(string $path = ''): string
{
    $base = array_key_exists('lezhai_base_path', $GLOBALS) ? rtrim((string)$GLOBALS['lezhai_base_path'], '/') : rtrim(Lezhai\Config::get('APP_BASE_PATH', '/brochure'), '/');
    return $base . ($path === '' ? '/' : '/' . ltrim($path, '/'));
}
