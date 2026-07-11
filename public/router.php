<?php
declare(strict_types=1);

$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$base = '/brochure';
$relative = str_starts_with($uri, $base) ? substr($uri, strlen($base)) : $uri;
$publicRoot = realpath(__DIR__);
$file = realpath(__DIR__ . $relative);
$isPublicFile = $publicRoot !== false
    && $file !== false
    && str_starts_with($file, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($file);
if ($relative !== '/' && $isPublicFile) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $knownTypes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
    ];
    $type = $knownTypes[$extension] ?? ((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream');
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
}
$_SERVER['BROCHURE_PATH'] = $relative ?: '/';
require __DIR__ . '/index.php';
