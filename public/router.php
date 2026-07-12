<?php
declare(strict_types=1);

$uri=rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/');
$roots=[realpath(__DIR__),realpath(dirname(__DIR__).'/storage/website-dist')];
if(in_array($uri,['/sitemap.xml','/robots.txt'],true))$roots=[];
foreach($roots as $root){
    if($root===false)continue;
    $candidates=[$uri];
    if($root===realpath(__DIR__)&&str_starts_with($uri,'/brochure/'))$candidates[]=substr($uri,strlen('/brochure'));
    foreach($candidates as $candidate){
    $file=realpath($root.$candidate);
    if($file!==false&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file)&&!str_ends_with(strtolower($file),'.html')){
        $extension=strtolower(pathinfo($file,PATHINFO_EXTENSION));
        $known=['css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8','svg'=>'image/svg+xml','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','avif'=>'image/avif','gif'=>'image/gif','ico'=>'image/x-icon','woff2'=>'font/woff2','mp4'=>'video/mp4','pdf'=>'application/pdf'];
        $type=$known[$extension]??((new finfo(FILEINFO_MIME_TYPE))->file($file)?:'application/octet-stream');
        header('Content-Type: '.$type);header('Content-Length: '.filesize($file));readfile($file);return true;
    }}
}
require __DIR__.'/index.php';
