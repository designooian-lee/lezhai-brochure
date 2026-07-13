<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$jobs=new Lezhai\CatalogJobService(Lezhai\Database::connection());
$catalogs=new Lezhai\CatalogService(Lezhai\Database::connection());
$jobs->recoverInterrupted();
while(true){
    $job=$jobs->claim();
    if(!$job){sleep(2);continue;}
    try{
        if($job['job_type']==='parse'){
            $result=$catalogs->preview((string)$job['source_url'],static function(string $phase)use($jobs,$job):void{$jobs->progress((int)$job['id'],0,0,$phase);});
            $jobs->completeParse((int)$job['id'],$result);continue;
        }
        $catalogs->buildLocalPages((int)$job['catalog_id'],static function(int $current,int $total,string $phase)use($jobs,$job):void{$jobs->progress((int)$job['id'],$current,$total,$phase);});
        $catalog=$catalogs->find((int)$job['catalog_id'],true);
        $jobs->complete((int)$job['id'],(string)($catalog['download_cache']??''));
    }catch(\Throwable $exception){error_log((string)$exception);$jobs->fail((int)$job['id'],$exception);}
}
