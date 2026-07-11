<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https: http:; style-src 'self'; script-src 'self'; frame-src https://book.yunzhan365.com https://flbook.com.cn http://book.goootu.com; form-action 'self'; base-uri 'self'; frame-ancestors 'self'");

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('lezhai_brochure');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'path' => base_path(),
    ]);
    session_start();
}

(new Lezhai\App())->run($_SERVER['BROCHURE_PATH'] ?? '/');
