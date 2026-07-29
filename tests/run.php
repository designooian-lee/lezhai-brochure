<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$passed = 0;
$failed = 0;
function check(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) { echo "[通过] {$message}\n"; $passed++; }
    else { fwrite(STDERR, "[失败] {$message}\n"); $failed++; }
}

$pdo = Lezhai\Database::connection();
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='catalogs'")->fetchColumn() === 1, '数据库结构已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name='catalogs' AND column_name IN ('reader_mode','local_page_count')")->fetchColumn() === 2, '本地阅读模式字段已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name='articles' AND column_name='seo_keywords'")->fetchColumn() === 1, '文章 SEO 关键字字段已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='catalog_jobs'")->fetchColumn() === 1, '图册后台任务表已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_name='catalog_jobs' AND column_name IN ('source_url','result_payload')")->fetchColumn() === 2, '图册解析任务字段已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('tutorials','tutorial_media')")->fetchColumn() === 2, '指纹锁教程数据表已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='articles'")->fetchColumn() === 1, '官网文章数据表已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='article_monthly_views'")->fetchColumn() === 1, '文章月度热点数据表已创建');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name='site_settings'")->fetchColumn() === 1, '网站备案设置表已创建');
check(extension_loaded('gd'), 'GD 图片处理扩展已启用');
check((int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() >= 1, '至少有一个分类');
check((int)$pdo->query('SELECT COUNT(DISTINCT source_type) FROM catalogs')->fetchColumn() >= 3, '已导入云展网、goootu、FLBOOK 三类样本');
$jobCatalogId=(int)$pdo->query('SELECT id FROM catalogs ORDER BY id LIMIT 1')->fetchColumn();$jobService=new Lezhai\CatalogJobService($pdo);$firstJob=$jobService->enqueue($jobCatalogId);$sameJob=$jobService->enqueue($jobCatalogId);check((int)$firstJob['id']===(int)$sameJob['id'],'同一图册不会重复创建活动任务');$pdo->prepare('DELETE FROM catalog_jobs WHERE id=?')->execute([$firstJob['id']]);
$parseSource='https://flbook.com.cn/c/qa-'.bin2hex(random_bytes(4));$parseJob=$jobService->enqueueParse($parseSource);$jobService->completeParse((int)$parseJob['id'],['source_url'=>$parseSource,'source_type'=>'flbook','title'=>'后台解析测试','description'=>'','cover_url'=>'/assets/cover-placeholder.svg','cover_source_url'=>'','pages'=>['https://example.com/1.jpg'],'pdf_url'=>null]);
$createdCatalog=(new Lezhai\CatalogService($pdo))->createFromParseJob(['category_id'=>(int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn(),'name'=>'','description'=>'','is_active'=>'1'],(int)$parseJob['id']);$parseCatalog=(int)$createdCatalog['id'];check($createdCatalog['created']&&$parseCatalog>0,'已完成解析任务可直接创建图册');
$duplicateJob=$jobService->enqueueParse($parseSource);$jobService->completeParse((int)$duplicateJob['id'],['source_url'=>$parseSource,'source_type'=>'flbook','title'=>'后台解析测试','description'=>'','cover_url'=>'/assets/cover-placeholder.svg','cover_source_url'=>'','pages'=>['https://example.com/1.jpg'],'pdf_url'=>null]);$existingCatalog=(new Lezhai\CatalogService($pdo))->createFromParseJob(['category_id'=>1],(int)$duplicateJob['id']);check(!$existingCatalog['created']&&(int)$existingCatalog['id']===$parseCatalog,'重复来源直接打开已存在图册，不显示数据库错误');
try{(new Lezhai\CatalogService($pdo))->createFromParseJob(['category_id'=>1,'name'=>''],(int)$parseJob['id']);check(false,'解析任务不能重复消费');}catch(Throwable $e){check(str_contains($e->getMessage(),'不可用'),'解析任务不能重复消费');}
(new Lezhai\CatalogService($pdo))->delete($parseCatalog);

$tutorials=new Lezhai\TutorialService($pdo);$tutorialId=$tutorials->save(['title'=>'附件接口测试','is_active'=>'1'],[],null);$media=$tutorials->addMedia($tutorialId,['media_type'=>'video','source_type'=>'external','media_url'=>'https://example.com/test.MP4?token=1','media_title'=>'测试附件','media_sort_order'=>2],[]);$tutorials->reorderMedia($tutorialId,[(int)$media['id']=>9]);$ordered=$tutorials->find($tutorialId,true);check((int)$ordered['media'][0]['sort_order']===9,'教程附件可独立添加并保存排序数字');$tutorials->deleteMedia((int)$media['id']);check(count($tutorials->find($tutorialId,true)['media'])===0,'教程附件可独立删除');$tutorials->delete($tutorialId);

$parser = new Lezhai\CatalogParser();
try { $parser->parse('https://example.com/book'); check(false, '未知域名被拒绝'); } catch (Throwable $e) { check(str_contains($e->getMessage(), '暂不支持'), '未知域名被拒绝'); }
try { $parser->parse('https://book.yunzhan365.com/wxfu/urim/mobile/index.htmll'); check(false, '错误扩展名提供建议'); } catch (Throwable $e) { check(str_contains($e->getMessage(), '建议修正'), '错误扩展名提供建议'); }
class FakeYunzhanHttpClient extends Lezhai\HttpClient {
    public function get(string $url, bool $resource = false): string {
        if (str_ends_with($url, '/mobile/javascript/config.js')) return 'var htmlConfig = {"fliphtml5_pages":"encrypted","pageEditor":[{},{}]};';
        return '<meta property="og:title" content="配置解析测试">';
    }
}
$configCatalog=(new Lezhai\CatalogParser(new FakeYunzhanHttpClient()))->parse('https://book.yunzhan365.com/test/book/mobile/index.html');
check($configCatalog['pages']===['browser-render://1','browser-render://2'],'加密云展网清单按公开页数解析，不启动浏览器');
$yunzhanExporter = file_get_contents(dirname(__DIR__) . '/scripts/yunzhan-export.js');
check(
    is_string($yunzhanExporter)
    && !str_contains($yunzhanExporter, '--single-process')
    && !str_contains($yunzhanExporter, '--renderer-process-limit')
    && !str_contains($yunzhanExporter, '--disable-software-rasterizer')
    && !str_contains($yunzhanExporter, '--max-old-space-size'),
    '云展网还原器不再限制浏览器进程和内存'
);
class OversizedGoootuHttpClient extends Lezhai\HttpClient {
    public function get(string $url, bool $resource = false): string { return '<title>超大图册</title>'; }
    public function post(string $url, bool $resource = false): string { return json_encode(['result'=>'ok','data'=>['uuid'=>'test','total_pages'=>2001]]); }
}
try { (new Lezhai\CatalogParser(new OversizedGoootuHttpClient()))->parse('http://book.goootu.com/User/Magazine/MagazineView.aspx?id=1'); check(false, '异常页数图册被拒绝'); }
catch (Throwable $e) { check(str_contains($e->getMessage(), '2000'), '异常页数图册被拒绝'); }

$service = new Lezhai\CatalogService($pdo);
$articleService = new Lezhai\ArticleService($pdo);
$sanitized=$articleService->sanitizeHtml('<p>安全正文</p><script>alert(1)</script><img src="/uploads/articles/payload.php"><a href="javascript:alert(1)">链接</a>');
check(str_contains($sanitized,'安全正文')&&!str_contains($sanitized,'script')&&!str_contains($sanitized,'payload.php')&&!str_contains($sanitized,'javascript:'),'导入文章正文会移除脚本、不安全图片和链接');
$draftId = $articleService->save(['title'=>'自动网址测试','slug'=>'','excerpt'=>'草稿','body_html'=>'<p>正文</p>','status'=>'draft'], null);
$draft = $articleService->find($draftId);
check(($draft['slug']??'')==='article-'.$draftId && ($draft['status']??'')==='draft', '空网址标识生成稳定 article-ID');
$pageResult=$articleService->page(999,6,true);
check($pageResult['page']===$pageResult['pages'] && count($pageResult['items'])<=6, '文章分页归一到有效范围');
$articleService->recordMonthlyView($draftId);
$monthlyCount=(int)$pdo->query('SELECT view_count FROM article_monthly_views WHERE article_id='.(int)$draftId)->fetchColumn();
check($monthlyCount===1,'文章月度点击可独立记录');
$articleService->delete($draftId);
$catalog = $pdo->query('SELECT id FROM catalogs ORDER BY id LIMIT 1')->fetch();
if ($catalog) {
    $visitor = bin2hex(random_bytes(16));
    $before = (int)$pdo->query('SELECT view_count FROM catalogs WHERE id=' . (int)$catalog['id'])->fetchColumn();
    check($service->recordView((int)$catalog['id'], $visitor) === true, '首次浏览计入热度');
    check($service->recordView((int)$catalog['id'], $visitor) === false, '同设备当天不重复计数');
    $visitorHash = hash_hmac('sha256', $visitor, Lezhai\Config::get('APP_SECRET'));
    $cleanup = $pdo->prepare('DELETE FROM catalog_daily_views WHERE catalog_id=? AND visitor_hash=?');
    $cleanup->execute([(int)$catalog['id'], $visitorHash]);
    $pdo->prepare('UPDATE catalogs SET view_count=? WHERE id=?')->execute([$before, (int)$catalog['id']]);
}
try { $service->recordView(PHP_INT_MAX, bin2hex(random_bytes(16))); check(false, '无效图册浏览会失败'); }
catch (Throwable) { check(!$pdo->inTransaction(), '浏览计数失败后事务已回滚'); }

class FakeImageHttpClient extends Lezhai\HttpClient {
    public function download(string $url, string $target): void {
        file_put_contents($target, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
    }
}
$categoryId = (int)$pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
$sourceUrl = 'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=' . random_int(900000, 999999);
$insert = $pdo->prepare("INSERT INTO catalogs(category_id,source_url,source_type,name,description,page_manifest,is_active,parse_status) VALUES(?,?, 'goootu','ZIP 自动测试','',?::jsonb,FALSE,'ok') RETURNING id");
$insert->execute([$categoryId, $sourceUrl, json_encode([
    'http://book.goootu.com/UpLoads/Magazine/InsidePages/test/1500/1.jpg',
    'http://book.goootu.com/UpLoads/Magazine/InsidePages/test/1500/2.jpg',
    'http://book.goootu.com/UpLoads/Magazine/InsidePages/test/1500/3.jpg',
])]);
$downloadId = (int)$insert->fetchColumn();
try {
    $downloadService = new Lezhai\CatalogService($pdo, null, new FakeImageHttpClient());
    $zipPath = $downloadService->buildDownload($downloadId);
    $zip = new ZipArchive();
    $opened = $zip->open($zipPath) === true;
    check($opened && $zip->numFiles === 3 && $zip->getNameIndex(0) === '0001.jpg' && $zip->getNameIndex(2) === '0003.jpg', 'ZIP 下载包按页码完整生成');
    if ($opened) $zip->close();
    $localCount = $downloadService->buildLocalPages($downloadId);
    $localPages = $downloadService->localPages($downloadId);
    check($localCount === 3 && count($localPages) === 3 && basename($localPages[0]) === '0001.jpg', '下载包可生成本地图片阅读');
    @unlink($zipPath);
} finally {
    $service->delete($downloadId);
}

$manager = new Lezhai\DataManager($pdo);
$settingsService = new Lezhai\SiteSettingsService($pdo);
$originalSettings = $settingsService->get();
try {
    $settingsService->save(['icp_number'=>'<b>粤ICP备12345678号</b>','police_number'=>'粤公网安备44130202000001号']);
    $filingHtml=$settingsService->footerHtml('/assets/police-filing.svg');
    check(str_contains($filingHtml,'&lt;b&gt;粤ICP备12345678号&lt;/b&gt;')&&!str_contains($filingHtml,'<b>')&&str_contains($filingHtml,'https://beian.miit.gov.cn/'),'备案文字经过转义并链接工信部');
    check(str_contains($filingHtml,'code=44130202000001')&&str_contains($filingHtml,'police-filing.svg'),'公安备案号生成查询链接和图标');
    try{$settingsService->save(['police_number'=>'粤公网安备123号']);check(false,'无效公安备案号被拒绝');}
    catch(Throwable $e){check(str_contains($e->getMessage(),'14 位'),'无效公安备案号被拒绝');}
    check($settingsService->normalize([])===['icp_number'=>'','police_number'=>''],'旧备份缺少备案设置时使用空值');
} finally {
    $settingsService->save($originalSettings);
}
$export = $manager->export();
check(($export['format'] ?? '') === 'lezhai-brochure-data' && count($export['catalogs'] ?? []) >= 1, '数据管理可导出标准 JSON');
check(isset($export['tutorials'], $export['tutorial_media']), '数据管理包含教程与附件');
check(isset($export['articles']), '数据管理包含官网文章');
check(isset($export['article_monthly_views']), '数据管理包含文章月度热点');
check(isset($export['site_settings']['icp_number'],$export['site_settings']['police_number']), '数据管理包含网站备案设置');
try { $manager->import(['format' => 'invalid']); check(false, '无效导入文件被拒绝'); }
catch (Throwable $e) { check(str_contains($e->getMessage(), '有效'), '无效导入文件被拒绝'); }
$unsafeExport=$export;
$unsafeExport['catalogs'][0]['source_url']='javascript:alert(1)';
try { $manager->import($unsafeExport); check(false, '导入中的不安全图册链接被拒绝'); }
catch (Throwable $e) { check(str_contains($e->getMessage(), '不安全'), '导入中的不安全图册链接被拒绝'); }
$unsafeZip=dirname(__DIR__).'/storage/runtime/unsafe-import-test.zip';
$zip=new ZipArchive();
$zip->open($unsafeZip,ZipArchive::CREATE|ZipArchive::OVERWRITE);
$zip->addFromString('data.json',json_encode($export,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$zip->addFromString('articles/payload.php','<?php echo "unsafe";');
$zip->close();
try { $manager->importBackupZip($unsafeZip); check(false, '备份 ZIP 中的可执行文件被拒绝'); }
catch (Throwable $e) { check(str_contains($e->getMessage(), '不允许'), '备份 ZIP 中的可执行文件被拒绝'); }
finally { @unlink($unsafeZip); }

$loginUsername='expired-lockout-test';
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$loginIdentity=hash('sha256','127.0.0.1|'.$loginUsername);
$pdo->prepare("INSERT INTO login_attempts(identity_hash,attempts,blocked_until,updated_at) VALUES(?,5,NOW()-INTERVAL '1 minute',NOW()-INTERVAL '16 minutes') ON CONFLICT(identity_hash) DO UPDATE SET attempts=5,blocked_until=NOW()-INTERVAL '1 minute',updated_at=NOW()-INTERVAL '16 minutes'")->execute([$loginIdentity]);
Lezhai\Auth::attempt($loginUsername,'wrong-password');
$loginAttempt=$pdo->prepare('SELECT attempts,blocked_until FROM login_attempts WHERE identity_hash=?');$loginAttempt->execute([$loginIdentity]);$loginAttempt=$loginAttempt->fetch();
check((int)$loginAttempt['attempts']===1&&$loginAttempt['blocked_until']===null,'过期登录锁定在首次失败后从 1 重新计数');
$pdo->prepare('DELETE FROM login_attempts WHERE identity_hash=?')->execute([$loginIdentity]);

if (in_array('--live', $argv, true)) {
    foreach ([
        'https://book.yunzhan365.com/wxfu/egep/mobile/index.html' => 'yunzhan365',
        'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=51007' => 'goootu',
        'https://flbook.com.cn/c/DUiKDGRoCO' => 'flbook',
    ] as $url => $type) {
        try { $result=$parser->parse($url); check($result['source_type']===$type && count($result['pages'])>0, "{$type} 在线解析"); }
        catch (Throwable $e) { check(false, "{$type} 在线解析：{$e->getMessage()}"); }
    }
}

echo "\n通过 {$passed}，失败 {$failed}\n";
exit($failed === 0 ? 0 : 1);
