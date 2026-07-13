<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if(PHP_SAPI!=='cli'&&(int)($_SERVER['CONTENT_LENGTH']??0)>510*1024*1024){
    http_response_code(413);
    if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>'上传内容超过 500MB 限制。'],JSON_UNESCAPED_UNICODE);}
    else{header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><meta charset="utf-8"><title>上传失败</title><main style="max-width:680px;margin:80px auto;font-family:sans-serif"><h1>上传文件过大</h1><p>单个附件最大支持 500MB，请选择较小的文件后重试。</p></main>';}
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https: http:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self' https:; frame-src https://book.yunzhan365.com https://flbook.com.cn http://book.goootu.com; form-action 'self' https:; base-uri 'self'; frame-ancestors 'self'");

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name('lezhai_platform');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'path'=>'/']);
    session_start();
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path === '/brochure/admin' || str_starts_with($path, '/brochure/admin/')) {
    $target = substr($path, strlen('/brochure')) ?: '/admin';
    header('Location: ' . $target, true, 301); exit;
}
if ($path === '/brochure' || str_starts_with($path, '/brochure/')) {
    $GLOBALS['lezhai_base_path']='/brochure';
    $relative=substr($path,strlen('/brochure')) ?: '/';
    (new Lezhai\App())->run($relative); exit;
}
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    $GLOBALS['lezhai_base_path']='';
    (new Lezhai\App())->run($path); exit;
}
$GLOBALS['lezhai_base_path']='';
(new Lezhai\WebsiteApp())->run($path);
