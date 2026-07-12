<?php
declare(strict_types=1);

namespace Lezhai;

use RuntimeException;

final class App
{
    private CatalogService $catalogs;
    private TutorialService $tutorials;
    private ArticleService $articles;

    public function __construct()
    {
        $this->catalogs = new CatalogService(Database::connection());
        $this->tutorials = new TutorialService(Database::connection());
        $this->articles = new ArticleService(Database::connection());
    }

    public function run(string $path): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        try {
            if ($path === '/health') {
                Database::connection()->query('SELECT 1');
                $this->json(['status' => 'ok']);
            } elseif ($path === '/' && $method === 'GET') {
                $this->home();
            } elseif ($path === '/tutorials' && $method === 'GET') {
                $this->tutorialsPage();
            } elseif (preg_match('~^/tutorial/(\d+)$~', $path, $m) && $method === 'GET') {
                $this->tutorialDetail((int)$m[1]);
            } elseif ($path === '/articles' && $method === 'GET') {
                $this->articlesPage();
            } elseif (preg_match('~^/articles/([a-z0-9-]+)$~', $path, $m) && $method === 'GET') {
                $this->brochureArticleDetail($m[1]);
            } elseif (preg_match('~^/catalog/(\d+)$~', $path, $m) && $method === 'GET') {
                $this->home((int) $m[1]);
            } elseif (preg_match('~^/reader/(\d+)$~', $path, $m) && $method === 'GET') {
                $this->reader((int) $m[1]);
            } elseif (preg_match('~^/catalog-pages/(\d+)/(\d{4}\.(?:jpg|jpeg|png|webp))$~i', $path, $m) && $method === 'GET') {
                $this->localPage((int) $m[1], $m[2]);
            } elseif (preg_match('~^/view/(\d+)$~', $path, $m) && $method === 'POST') {
                $this->recordView((int) $m[1]);
            } elseif ($path === '/admin/login') {
                $this->login($method);
            } elseif ($path === '/admin/logout' && $method === 'POST') {
                Auth::verifyCsrf(); Auth::logout(); header('Location: ' . base_path('admin/login'));
            } elseif ($path === '/admin' && $method === 'GET') {
                Auth::requireLogin(); $this->admin();
            } elseif ($path === '/admin/data' && $method === 'GET') {
                Auth::requireLogin(); $this->dataManagement();
            } elseif ($path === '/admin/articles/new') {
                Auth::requireLogin(); $this->articleForm($method);
            } elseif (preg_match('~^/admin/articles/(\d+)/preview$~', $path, $m) && $method==='GET') {
                Auth::requireLogin(); $this->articlePreview((int)$m[1]);
            } elseif (preg_match('~^/admin/articles/(\d+)/edit$~', $path, $m)) {
                Auth::requireLogin(); $this->articleForm($method,(int)$m[1]);
            } elseif (preg_match('~^/admin/articles/(\d+)/delete$~', $path, $m) && $method==='POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->articles->delete((int)$m[1]); $this->flash('文章已删除。'); $this->redirectAdmin();
            } elseif ($path === '/admin/articles/image' && $method==='POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); try{$this->json(['url'=>$this->articles->uploadBodyImage($_FILES['image']??[])]);}catch(RuntimeException $exception){http_response_code(422);$this->json(['error'=>$exception->getMessage()]);}
            } elseif ($path === '/admin/tutorials/new') {
                Auth::requireLogin(); $this->tutorialForm($method);
            } elseif (preg_match('~^/admin/tutorials/(\d+)/edit$~', $path, $m)) {
                Auth::requireLogin(); $this->tutorialForm($method,(int)$m[1]);
            } elseif (preg_match('~^/admin/tutorials/(\d+)/delete$~', $path, $m) && $method==='POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->tutorials->delete((int)$m[1]); $this->flash('教程已删除。'); $this->redirectAdmin();
            } elseif (preg_match('~^/admin/tutorial-media/(\d+)/delete$~', $path, $m) && $method==='POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->tutorials->deleteMedia((int)$m[1]); $this->flash('附件已删除。'); $this->redirectAdmin();
            } elseif ($path === '/admin/data/export' && $method === 'POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->exportData();
            } elseif ($path === '/admin/data/import' && $method === 'POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->importData();
            } elseif ($path === '/admin/categories/new') {
                Auth::requireLogin(); $this->categoryForm($method);
            } elseif (preg_match('~^/admin/categories/(\d+)/edit$~', $path, $m)) {
                Auth::requireLogin(); $this->categoryForm($method, (int) $m[1]);
            } elseif (preg_match('~^/admin/categories/(\d+)/delete$~', $path, $m) && $method === 'POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->catalogs->deleteCategory((int) $m[1]); $this->flash('分类已删除。'); $this->redirectAdmin();
            } elseif ($path === '/admin/catalogs/new') {
                Auth::requireLogin(); $this->catalogNew($method);
            } elseif (preg_match('~^/admin/catalogs/(\d+)/edit$~', $path, $m)) {
                Auth::requireLogin(); $this->catalogEdit($method, (int) $m[1]);
            } elseif (preg_match('~^/admin/catalogs/(\d+)/reparse$~', $path, $m) && $method === 'POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->catalogs->reparse((int) $m[1]); $this->flash('图册已重新解析。'); $this->redirectAdmin();
            } elseif (preg_match('~^/admin/catalogs/(\d+)/delete$~', $path, $m) && $method === 'POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->catalogs->delete((int) $m[1]); $this->flash('图册已删除。'); $this->redirectAdmin();
            } elseif (preg_match('~^/admin/catalogs/(\d+)/download$~', $path, $m) && $method === 'POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $this->download((int) $m[1]);
            } elseif (preg_match('~^/admin/catalogs/(\d+)/local-pages$~', $path, $m) && $method === 'POST') {
                Auth::requireLogin(); Auth::verifyCsrf(); $count = $this->catalogs->buildLocalPages((int) $m[1]); $this->flash("已生成 {$count} 张本地高清页面。"); header('Location: ' . base_path('admin/catalogs/' . (int) $m[1] . '/edit')); exit;
            } else {
                http_response_code(404); $this->layout('没有找到页面', '<main class="empty"><h1>页面不存在</h1><a class="button" href="' . e(base_path()) . '">返回图册首页</a></main>');
            }
        } catch (\Throwable $e) {
            error_log((string) $e);
            $message = Config::get('APP_ENV', 'production') === 'local'
                ? $e->getMessage()
                : '服务器暂时无法完成请求，请稍后重试。';
            if (str_starts_with($path, '/admin')) {
                $this->layout('操作未完成', '<main class="admin-shell"><div class="notice error"><strong>操作未完成</strong><p>' . e($message) . '</p></div><a class="button secondary" href="' . e(base_path('admin')) . '">返回后台</a></main>', true);
            } else {
                http_response_code(500); $this->layout('暂时无法打开', '<main class="empty"><h1>暂时无法打开</h1><p>' . e($message) . '</p><a class="button" href="' . e(base_path()) . '">返回首页</a></main>');
            }
        }
    }

    private function home(?int $autoOpenId = null): void
    {
        $autoOpen = $autoOpenId ? $this->catalogs->find($autoOpenId) : null;
        if ($autoOpenId && !$autoOpen) { http_response_code(404); $this->layout('图册不存在', '<main class="empty"><h1>图册不存在或已隐藏</h1><a class="button" href="' . e(base_path()) . '">查看全部图册</a></main>'); return; }
        $categoryId = isset($_GET['category']) ? (int) $_GET['category'] : null;
        $categories = $this->catalogs->categories();
        if ($categoryId && !array_filter($categories, static fn ($c) => (int) $c['id'] === $categoryId)) {
            $categoryId = null;
        }
        $catalogs = $this->catalogs->catalogs($categoryId);
        $categoryName = '全部图册';
        foreach ($categories as $category) {
            if ((int) $category['id'] === $categoryId) $categoryName = $category['name'];
        }
        ob_start(); ?>
        <header class="site-header">
            <a class="brand-placeholder" href="<?= e(base_path()) ?>" aria-label="乐宅.Life 图册首页"><span class="brand-cn">乐宅.Life</span></a>
            <div class="site-header-actions">
                <p>把喜欢的门，装进生活</p>
                <a class="header-link" href="https://www.lezhai.life/" target="_blank" rel="noopener noreferrer">进入官网</a>
            </div>
        </header>
        <?= $this->publicNav('catalogs') ?>
        <main class="public-main">
            <nav class="category-tabs" aria-label="图册分类">
                <button type="button" class="favorites-tab" data-favorites-filter aria-pressed="false">我的收藏 <span data-favorites-count>0</span></button>
                <a class="<?= $categoryId ? '' : 'active' ?>" href="<?= e(base_path()) ?>">全部</a>
                <?php foreach ($categories as $category): ?>
                    <a class="<?= $categoryId === (int) $category['id'] ? 'active' : '' ?>" href="<?= e(base_path('?category=' . $category['id'])) ?>"><?= e($category['name']) ?></a>
                <?php endforeach; ?>
            </nav>
            <section class="section-block">
                <div class="section-heading"><div><span class="eyebrow">CATALOGS</span><h2><?= e($categoryName) ?></h2></div><span class="section-note"><?= count($catalogs) ?> 本</span></div>
                <?php if ($catalogs): ?><div class="catalog-grid" data-client-pagination="12"><?php foreach ($catalogs as $catalog) echo $this->card($catalog); ?></div><div class="public-pagination" data-client-pagination-nav></div>
                <?php else: ?><div class="empty-card"><span>暂无图册</span><p>请稍后再来看看。</p></div><?php endif; ?>
                <div class="empty-card favorites-empty" data-favorites-empty hidden><span>还没有收藏</span><p>点击图册卡片上的“收藏”，下次打开仍会保留。</p></div>
            </section>
        </main>
        <aside class="warm-tip"><span aria-hidden="true">✦</span><strong>温馨提示</strong><span>看到喜欢的款式，直接截图发给客服</span></aside>
        <dialog id="reader-dialog" class="reader-dialog"<?php if ($autoOpen): ?> data-auto-reader-url="<?= e(base_path('reader/' . $autoOpen['id'])) ?>" data-auto-view-url="<?= e(base_path('view/' . $autoOpen['id'])) ?>" data-auto-title="<?= e($autoOpen['name']) ?>"<?php endif; ?>><div class="reader-toolbar"><button type="button" data-close-reader aria-label="关闭图册">‹ 返回选款</button><strong id="reader-title">正在打开图册</strong><a class="reader-contact" href="https://www.lezhai.life/contact/" target="_blank" rel="noopener noreferrer">联系我们</a></div><div id="reader-content" class="reader-content"><div class="reader-loading">正在载入图册…</div></div></dialog>
        <?php $this->layout($autoOpen ? $autoOpen['name'] : '图册选款', (string) ob_get_clean());
    }

    private function publicNav(string $active): string
    {
        return '<nav class="primary-nav" aria-label="主要导航"><a class="'.($active==='catalogs'?'active':'').'" href="'.e(base_path()).'">电子图册</a><a class="'.($active==='tutorials'?'active':'').'" href="'.e(base_path('tutorials')).'">指纹锁教程</a><a class="'.($active==='articles'?'active':'').'" href="'.e(base_path('articles')).'">文章</a></nav>';
    }

    private function articlesPage(): void
    {
        $result=$this->articles->page((int)($_GET['page']??1),8);$items=$result['items'];ob_start();?><header class="site-header"><a class="brand-placeholder" href="<?=e(base_path())?>"><span class="brand-cn">乐宅.Life</span></a><div class="site-header-actions"><p>好设计，更要好落地</p><a class="header-link" href="/articles">官网文章</a></div></header><?=$this->publicNav('articles')?><main class="public-main"><section class="section-block"><div class="section-heading"><div><span class="eyebrow">JOURNAL</span><h1>文章</h1></div><span class="section-note"><?=$result['total']?> 篇</span></div><?php if($items):?><div class="catalog-grid tutorial-grid"><?php foreach($items as $article):?><article class="catalog-card tutorial-card"><a class="catalog-open" href="<?=e(base_path('articles/'.$article['slug']))?>"><span class="cover-wrap"><?php if($article['cover_path']):?><img src="<?=e($article['cover_path'])?>" alt="<?=e($article['title'])?>封面" loading="lazy"><?php endif?></span><span class="card-copy"><small><?=e(date('Y.m.d',strtotime((string)$article['published_at'])))?></small><strong><?=e($article['title'])?></strong><span><?=e($article['excerpt'])?></span><i>阅读全文 <b>→</b></i></span></a></article><?php endforeach?></div><?=$this->publicPagination(base_path('articles'),$result['page'],$result['pages'])?><?php else:?><div class="empty-card"><span>文章正在整理中</span><p>欢迎稍后再来。</p></div><?php endif?></section></main><?php $this->layout('文章',(string)ob_get_clean(),false,'<link rel="canonical" href="https://lezhai.life/articles'.($result['page']>1?'?page='.$result['page']:'').'">');
    }

    private function brochureArticleDetail(string $slug): void
    {
        $preview=($_GET['preview']??'')==='1'&&Auth::check();$article=$this->articles->findBySlug($slug,$preview);
        if(!$article){http_response_code(404);$this->layout('文章不存在','<main class="empty"><h1>文章不存在或尚未发布</h1></main>');return;}
        $this->renderBrochureArticle($article,$preview);
    }

    private function renderBrochureArticle(array $article,bool $preview=false): void
    {
        $neighbors=$this->articles->neighbors($article);ob_start();?><header class="site-header"><a class="brand-placeholder" href="<?=e(base_path())?>"><span class="brand-cn">乐宅.Life</span></a></header><?=$this->publicNav('articles')?><main class="tutorial-detail"><a class="text-link" href="<?=e(base_path('articles'))?>">← 返回文章列表</a><article><div class="tutorial-title"><span class="eyebrow">JOURNAL</span><h1><?=e($article['title'])?></h1><p><?=e($article['excerpt'])?></p></div><?php if($article['cover_path']):?><img class="tutorial-hero" src="<?=e($article['cover_path'])?>" alt="<?=e($article['title'])?>"><?php endif?><div class="article-body rich-html"><?=$article['body_html']?></div><?=$this->articleNeighbors($neighbors,true)?></article></main><?php $canonical='<link rel="canonical" href="https://lezhai.life/articles/'.e($article['slug']).'">';$this->layout($article['seo_title']?:$article['title'],(string)ob_get_clean(),false,$canonical);
    }

    private function tutorialsPage(): void
    {
        $result=$this->slicePage($this->tutorials->all(),(int)($_GET['page']??1),8);$items=$result['items']; ob_start(); ?>
        <header class="site-header"><a class="brand-placeholder" href="<?=e(base_path())?>"><span class="brand-cn">乐宅.Life</span></a><div class="site-header-actions"><p>好设计，更要好落地</p><a class="header-link" href="https://www.lezhai.life/" target="_blank" rel="noopener noreferrer">进入官网</a></div></header>
        <?=$this->publicNav('tutorials')?>
        <main class="public-main"><section class="section-block"><div class="section-heading"><div><span class="eyebrow">SMART LOCK GUIDES</span><h1>指纹锁教程</h1></div><span class="section-note"><?=$result['total']?> 篇</span></div>
        <?php if($items):?><div class="catalog-grid tutorial-grid"><?php foreach($items as $t): $cover=$t['cover_path']?base_path(ltrim($t['cover_path'],'/')):base_path('assets/cover-placeholder.svg');?><article class="catalog-card tutorial-card" data-tutorial-id="<?=(int)$t['id']?>"><a class="catalog-open" href="<?=e(base_path('tutorial/'.$t['id']))?>"><span class="cover-wrap"><img src="<?=e($cover)?>" alt="<?=e($t['title'])?>封面" loading="lazy"></span><span class="card-copy"><small>指纹锁教程</small><strong><?=e($t['title'])?></strong><span><?=e($t['description']?:'查看完整安装与使用说明')?></span><i>查看教程 <b>→</b></i></span></a><div class="tutorial-card-actions"><button class="favorite-button" data-tutorial-favorite="<?=(int)$t['id']?>" aria-pressed="false"><span>♡</span> 收藏</button><button class="share-button" data-share-url="<?=e(base_path('tutorial/'.$t['id']))?>" data-share-title="<?=e($t['title'])?>"><span>↗</span> 分享</button></div></article><?php endforeach?></div><?=$this->publicPagination(base_path('tutorials'),$result['page'],$result['pages'])?><?php else:?><div class="empty-card"><span>暂无教程</span><p>教程整理后会在这里展示。</p></div><?php endif?></section></main>
        <?php $this->layout('指纹锁教程',(string)ob_get_clean());
    }

    private function tutorialDetail(int $id): void
    {
        $t=$this->tutorials->find($id); if(!$t){http_response_code(404);$this->layout('教程不存在','<main class="empty"><h1>教程不存在或已隐藏</h1><a class="button" href="'.e(base_path('tutorials')).'">返回教程列表</a></main>');return;} ob_start();?>
        <header class="site-header"><a class="brand-placeholder" href="<?=e(base_path())?>"><span class="brand-cn">乐宅.Life</span></a></header><?=$this->publicNav('tutorials')?>
        <main class="tutorial-detail"><a class="text-link" href="<?=e(base_path('tutorials'))?>">← 返回指纹锁教程</a><article><div class="tutorial-title"><span class="eyebrow">SMART LOCK GUIDE</span><h1><?=e($t['title'])?></h1><p><?=e($t['description'])?></p><div class="tutorial-detail-actions"><button class="favorite-button inline" data-tutorial-favorite="<?=$id?>">♡ 收藏</button><button class="share-button inline" data-share-url="<?=e(base_path('tutorial/'.$id))?>" data-share-title="<?=e($t['title'])?>">↗ 分享</button></div></div><?php if($t['cover_path']):?><img class="tutorial-hero" src="<?=e(base_path(ltrim($t['cover_path'],'/')))?>" alt="<?=e($t['title'])?>"><?php endif?><div class="article-body"><?=nl2br(e($t['body']))?></div><?php foreach($t['media'] as $m):?><section class="tutorial-media"><h2><?=e($m['title']?:($m['media_type']==='video'?'视频教程':'教程文档'))?></h2><?php $src=$m['source_type']==='upload'?base_path(ltrim($m['file_path'],'/')):$m['url']; if($m['media_type']==='video'&&$m['source_type']==='upload'):?><video controls preload="metadata" src="<?=e($src)?>"></video><?php elseif($m['media_type']==='video'):?><a class="button" href="<?=e($src)?>" target="_blank" rel="noopener noreferrer">打开视频教程</a><?php elseif($m['mime_type']==='application/pdf'):?><iframe class="document-frame" src="<?=e($src)?>" title="<?=e($m['title'])?>"></iframe><a class="text-link" href="<?=e($src)?>" download>下载 PDF</a><?php else:?><a class="button secondary" href="<?=e($src)?>" download>下载教程文档</a><?php endif?></section><?php endforeach?></article></main>
        <?php $this->layout($t['title'],(string)ob_get_clean());
    }

    private function card(array $catalog, bool $hot = false, int $rank = 0): string
    {
        $cover = $catalog['cover_path'] ? base_path(ltrim($catalog['cover_path'], '/')) : base_path('assets/cover-placeholder.svg');
        ob_start(); ?>
        <article class="catalog-card <?= $hot ? 'hot-card' : '' ?>" data-catalog-id="<?= (int) $catalog['id'] ?>">
            <button class="catalog-open" type="button" data-reader-url="<?= e(base_path('reader/' . $catalog['id'])) ?>" data-view-url="<?= e(base_path('view/' . $catalog['id'])) ?>" data-catalog-title="<?= e($catalog['name']) ?>">
                <span class="cover-wrap"><img src="<?= e($cover) ?>" alt="<?= e($catalog['name']) ?>封面" loading="lazy"><?php if ($hot): ?><em class="rank">TOP <?= $rank ?></em><?php endif; ?></span>
                <span class="card-copy"><small><?= e($catalog['category_name']) ?></small><strong><?= e($catalog['name']) ?></strong><span><?= e($catalog['description'] ?: '点击查看完整电子图册') ?></span><i>查看图册 <b>→</b></i></span>
            </button>
            <button class="favorite-button" type="button" data-favorite-id="<?= (int) $catalog['id'] ?>" aria-pressed="false"><span aria-hidden="true">♡</span> 收藏</button>
            <button class="share-button" type="button" data-share-url="<?= e(base_path('catalog/' . $catalog['id'])) ?>" data-share-title="<?= e($catalog['name']) ?>"><span aria-hidden="true">↗</span> 分享</button>
        </article>
        <?php return (string) ob_get_clean();
    }

    private function reader(int $id): void
    {
        $catalog = $this->catalogs->find($id);
        if (!$catalog) { http_response_code(404); exit('图册不存在或已隐藏。'); }
        $pages = [];
        if ($catalog['reader_mode'] === 'local') {
            foreach ($this->catalogs->localPages($id) as $file) $pages[] = base_path('catalog-pages/' . $id . '/' . basename($file));
        } elseif ($catalog['source_type'] === 'goootu') {
            $pages = json_decode($catalog['page_manifest'], true) ?: [];
        }
        if ($pages !== []) {
            echo '<div class="image-reader" data-image-reader><div class="image-stage"><button class="page-nav prev" data-prev aria-label="上一页">‹</button><img data-page-image src="' . e($pages[0] ?? '') . '" alt="' . e($catalog['name']) . ' 第1页"><button class="page-nav next" data-next aria-label="下一页">›</button></div><div class="page-counter"><span data-current>1</span> / ' . count($pages) . '</div><script type="application/json" data-pages>' . json_encode($pages, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . '</script></div>';
        } else {
            echo '<iframe class="catalog-frame" src="' . e($catalog['source_url']) . '" title="' . e($catalog['name']) . '" allow="fullscreen; autoplay" referrerpolicy="strict-origin-when-cross-origin"></iframe>';
        }
    }

    private function localPage(int $id, string $name): never
    {
        $catalog = $this->catalogs->find($id);
        $file = $catalog ? $this->catalogs->localPageFile($id, $name) : null;
        if (!$file) { http_response_code(404); exit('页面不存在。'); }
        $type = (new \finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: public, max-age=86400');
        readfile($file); exit;
    }

    private function recordView(int $id): void
    {
        $visitor = $_COOKIE['lezhai_visitor'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/', $visitor)) {
            $visitor = bin2hex(random_bytes(16));
            setcookie('lezhai_visitor', $visitor, ['expires' => time() + 31536000, 'path' => base_path(), 'httponly' => true, 'samesite' => 'Lax']);
        }
        $this->json(['counted' => $this->catalogs->recordView($id, $visitor)]);
    }

    private function login(string $method): void
    {
        if (Auth::check()) { $this->redirectAdmin(); }
        $error = '';
        if ($method === 'POST') {
            Auth::verifyCsrf();
            if (Auth::attempt(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''))) $this->redirectAdmin();
            $error = '账号或密码不正确；连续失败 5 次将暂停登录 15 分钟。';
        }
        ob_start(); ?>
        <main class="login-page"><section class="login-card"><div class="brand-placeholder"><span class="brand-cn">乐宅.Life</span><span class="brand-en">图册管理后台</span></div><h1>管理员登录</h1><p>管理分类、图册链接与高清下载。</p><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><label>管理员账号<input name="username" required autocomplete="username"></label><label>密码<input type="password" name="password" required autocomplete="current-password"></label><button class="button" type="submit">进入后台</button></form><a class="text-link" href="<?= e(base_path()) ?>">← 返回客户页面</a></section></main>
        <?php $this->layout('管理员登录', (string) ob_get_clean(), true);
    }

    private function admin(): void
    {
        $allArticles=$this->articles->all(true); $categories=$this->catalogs->categories(true); $allCatalogs=$this->catalogs->catalogs(null,true); $allTutorials=$this->tutorials->all(true); $perPage=10;
        $articlePage=max(1,(int)($_GET['article_page']??1));$catalogPage=max(1,(int)($_GET['catalog_page']??1)); $tutorialPage=max(1,(int)($_GET['tutorial_page']??1));
        $articlePages=max(1,(int)ceil(count($allArticles)/$perPage));
        $catalogPages=max(1,(int)ceil(count($allCatalogs)/$perPage)); $tutorialPages=max(1,(int)ceil(count($allTutorials)/$perPage));
        $articlePage=min($articlePage,$articlePages);$catalogPage=min($catalogPage,$catalogPages); $tutorialPage=min($tutorialPage,$tutorialPages);
        $articles=array_slice($allArticles,($articlePage-1)*$perPage,$perPage);$catalogs=array_slice($allCatalogs,($catalogPage-1)*$perPage,$perPage); $tutorials=array_slice($allTutorials,($tutorialPage-1)*$perPage,$perPage);
        $flash=$_SESSION['flash']??''; unset($_SESSION['flash']);
        ob_start(); ?>
        <main class="admin-shell"><?= $this->adminHeader('管理总览') ?><?php if ($flash): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?><div class="admin-quick-links"><a class="button secondary" href="<?= e(base_path('admin/data')) ?>">数据导入导出</a></div>
            <section class="admin-section"><div class="admin-heading"><div><h2>官网文章</h2><p>同一内容用于官网与图册平台，两套页面分别呈现</p></div><a class="button" href="<?=e(base_path('admin/articles/new'))?>">写文章</a></div><div class="table-wrap"><table><thead><tr><th>文章</th><th>网址标识</th><th>状态</th><th>发布时间</th><th></th></tr></thead><tbody><?php foreach($articles as $article):?><tr><td><strong><?=e($article['title'])?></strong><small><?=e($article['excerpt'])?></small></td><td><code><?=e($article['slug'])?></code></td><td><span class="status <?=$article['status']==='published'?'ok':'muted'?>"><?=$article['status']==='published'?'已发布':'草稿'?></span></td><td><?=e($article['published_at']?date('Y-m-d',strtotime((string)$article['published_at'])):'—')?></td><td class="actions"><a href="<?=e(base_path('admin/articles/'.$article['id'].'/edit'))?>">编辑</a><a href="/articles/<?=e($article['slug'])?>?preview=1" target="_blank">预览</a><form method="post" action="<?=e(base_path('admin/articles/'.$article['id'].'/delete'))?>" onsubmit="return confirm('确定删除这篇文章吗？')"><input type="hidden" name="_csrf" value="<?=e(Auth::csrf())?>"><button>删除</button></form></td></tr><?php endforeach?></tbody></table></div></section>
            <section class="admin-section admin-fixed-section"><div class="admin-heading"><div><h2>分类</h2><p>控制前台分类标签与显示顺序</p></div><a class="button" href="<?= e(base_path('admin/categories/new')) ?>">新增分类</a></div><div class="table-wrap"><table><thead><tr><th>名称</th><th>排序</th><th>图册</th><th>状态</th><th></th></tr></thead><tbody><?php foreach ($categories as $c): ?><tr><td><?= e($c['name']) ?></td><td><?= (int) $c['sort_order'] ?></td><td><?= (int) $c['catalog_count'] ?></td><td><span class="status <?= $c['is_active'] ? 'ok' : 'muted' ?>"><?= $c['is_active'] ? '显示中' : '已隐藏' ?></span></td><td class="actions"><a href="<?= e(base_path('admin/categories/' . $c['id'] . '/edit')) ?>">编辑</a><form method="post" action="<?= e(base_path('admin/categories/' . $c['id'] . '/delete')) ?>" onsubmit="return confirm('确定删除这个空分类吗？')"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><button type="submit">删除</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
            <section class="admin-section"><div class="admin-heading"><div><h2>电子图册</h2><p>人工排序优先，同值按客户热度排列</p></div><a class="button" href="<?= e(base_path('admin/catalogs/new')) ?>">添加图册</a></div><div class="table-wrap"><table class="catalog-table"><thead><tr><th>图册</th><th>分类</th><th>来源</th><th>排序/热度</th><th>状态</th><th></th></tr></thead><tbody><?php foreach ($catalogs as $c): ?><tr><td><div class="table-catalog"><img src="<?= e($c['cover_path'] ? base_path(ltrim($c['cover_path'], '/')) : base_path('assets/cover-placeholder.svg')) ?>" alt=""><div><strong><?= e($c['name']) ?></strong><small><?= e($c['description']) ?></small></div></div></td><td><?= e($c['category_name']) ?></td><td><?= e($this->sourceLabel($c['source_type'])) ?></td><td><?= (int) $c['manual_priority'] ?> / <?= (int) $c['view_count'] ?></td><td><span class="status <?= $c['parse_status'] === 'ok' && $c['is_active'] ? 'ok' : 'muted' ?>"><?= $c['parse_status'] === 'ok' ? ($c['is_active'] ? '显示中' : '已隐藏') : '解析异常' ?></span><?php if ($c['parse_error']): ?><small class="error-text"><?= e($c['parse_error']) ?></small><?php endif; ?></td><td class="actions"><a href="<?= e(base_path('admin/catalogs/' . $c['id'] . '/edit')) ?>">编辑</a><form method="post" action="<?= e(base_path('admin/catalogs/' . $c['id'] . '/reparse')) ?>"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><button type="submit">重新解析</button></form><form method="post" action="<?= e(base_path('admin/catalogs/' . $c['id'] . '/download')) ?>"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><button type="submit">高清下载</button></form><form method="post" action="<?= e(base_path('admin/catalogs/' . $c['id'] . '/delete')) ?>" onsubmit="return confirm('确定删除这本图册吗？')"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><button type="submit">删除</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
            <section class="admin-section admin-fixed-section"><div class="admin-heading"><div><h2>指纹锁教程</h2><p>管理文章、视频和文档附件</p></div><a class="button" href="<?=e(base_path('admin/tutorials/new'))?>">添加教程</a></div><div class="table-wrap"><table><thead><tr><th>教程</th><th>附件</th><th>排序</th><th>状态</th><th></th></tr></thead><tbody><?php foreach($tutorials as $t):?><tr><td><strong><?=e($t['title'])?></strong><small><?=e($t['description'])?></small></td><td><?=(int)$t['media_count']?></td><td><?=(int)$t['manual_priority']?></td><td><span class="status <?=$t['is_active']?'ok':'muted'?>"><?=$t['is_active']?'显示中':'已隐藏'?></span></td><td class="actions"><a href="<?=e(base_path('admin/tutorials/'.$t['id'].'/edit'))?>">编辑</a><form method="post" action="<?=e(base_path('admin/tutorials/'.$t['id'].'/delete'))?>" onsubmit="return confirm('确定删除这篇教程及全部附件吗？')"><input type="hidden" name="_csrf" value="<?=e(Auth::csrf())?>"><button>删除</button></form></td></tr><?php endforeach?></tbody></table></div></section>
            <div class="admin-pagination-slot" data-pagination-heading="官网文章"><?=$this->pagination('article_page',$articlePage,$articlePages,['catalog_page'=>$catalogPage,'tutorial_page'=>$tutorialPage])?></div>
            <div class="admin-pagination-slot" data-pagination-heading="电子图册"><?=$this->pagination('catalog_page',$catalogPage,$catalogPages,['article_page'=>$articlePage,'tutorial_page'=>$tutorialPage])?></div>
            <div class="admin-pagination-slot" data-pagination-heading="指纹锁教程"><?=$this->pagination('tutorial_page',$tutorialPage,$tutorialPages,['article_page'=>$articlePage,'catalog_page'=>$catalogPage])?></div>
        </main><?php $this->layout('管理后台', (string) ob_get_clean(), true);
    }

    private function pagination(string $parameter,int $current,int $pages,array $preserve=[]): string
    {
        if($pages<=1)return '<span class="pagination-single">共 1 页</span>';
        $html='<nav class="admin-pagination" aria-label="分页">';
        for($page=1;$page<=$pages;$page++){ $query=$preserve; $query[$parameter]=$page; $html.='<a class="'.($page===$current?'active':'').'" '.($page===$current?'aria-current="page" ':'').'href="'.e(base_path('admin?'.http_build_query($query))).'">'.$page.'</a>'; }
        return $html.'</nav>';
    }

    private function slicePage(array $items,int $page,int $perPage): array
    {
        $total=count($items);$pages=max(1,(int)ceil($total/$perPage));$page=min(max(1,$page),$pages);
        return ['items'=>array_slice($items,($page-1)*$perPage,$perPage),'page'=>$page,'pages'=>$pages,'total'=>$total];
    }

    private function publicPagination(string $path,int $current,int $pages): string
    {
        if($pages<=1)return '';$html='<nav class="public-pagination" aria-label="分页">';
        for($page=1;$page<=$pages;$page++)$html.='<a class="'.($page===$current?'active':'').'" '.($page===$current?'aria-current="page" ':'').'href="'.e($path.($page>1?'?page='.$page:'')).'">'.$page.'</a>';
        return $html.'</nav>';
    }

    private function articleNeighbors(array $neighbors,bool $brochure): string
    {
        $html='<nav class="article-neighbors" aria-label="相邻文章">';
        foreach(['previous'=>'上一篇','next'=>'下一篇'] as $key=>$label){$item=$neighbors[$key]??null;if(!$item)continue;$href=$brochure?base_path('articles/'.$item['slug']):'/articles/'.$item['slug'];$html.='<a class="'.$key.'" href="'.e($href).'"><span>'.$label.'</span><strong>'.e($item['title']).'</strong></a>';}
        return $html.'</nav>';
    }

    private function articleForm(string $method, ?int $id=null): void
    {
        $article=$id?$this->articles->find($id):null;if($id&&!$article)throw new RuntimeException('文章不存在。');$error='';
        if($method==='POST'){try{Auth::verifyCsrf();$saved=$this->articles->save($_POST,$_FILES['cover']??null,$id);$this->flash($id?'文章已更新。':'文章已创建。');header('Location: '.base_path('admin/articles/'.$saved.'/edit'));exit;}catch(\Throwable $exception){$error=$exception->getMessage();$article=array_merge($article??[],$_POST);}}
        ob_start();?><main class="admin-shell"><?=$this->adminHeader($id?'编辑文章':'写文章')?><?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif?><section class="form-card"><form method="post" enctype="multipart/form-data" data-article-form><input type="hidden" name="_csrf" value="<?=e(Auth::csrf())?>"><label>文章标题<input name="title" maxlength="180" required value="<?=e($article['title']??'')?>"></label><label>英文网址标识（可选）<input name="slug" maxlength="180" pattern="[a-z0-9-]*" placeholder="留空自动生成 article-编号" value="<?=e($article['slug']??'')?>"><small>留空将自动生成；发布后请勿修改，以免已有链接失效。</small></label><label>文章摘要<textarea name="excerpt" maxlength="500" rows="3"><?=e($article['excerpt']??'')?></textarea></label><label>封面图片<input type="file" name="cover" accept="image/jpeg,image/png,image/webp,image/gif"><small>建议尺寸：1200 × 675px，JPG/PNG/WebP，最大 8MB；未上传时自动使用正文第一张图片。</small></label><div class="rich-editor-field"><span>文章正文</span><div class="editor-toolbar" role="toolbar"><button type="button" data-command="bold">加粗</button><button type="button" data-block="h2">标题</button><button type="button" data-command="insertUnorderedList">列表</button><button type="button" data-link>链接</button><button type="button" data-image>插入图片</button><input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-image-input hidden></div><div class="rich-editor" contenteditable="true" data-editor><?=($article['body_html']??'')?></div><textarea name="body_html" data-editor-source hidden><?=e($article['body_html']??'')?></textarea><small>支持选择、粘贴或拖拽图片；图片会自动压缩并保存到本站。</small></div><label>SEO 标题<input name="seo_title" maxlength="180" value="<?=e($article['seo_title']??'')?>"><small>留空时使用文章标题。</small></label><label>Meta Description<textarea name="meta_description" maxlength="300" rows="3"><?=e($article['meta_description']??'')?></textarea><small>留空时使用文章摘要。</small></label><label>发布状态<select name="status"><option value="draft" <?=($article['status']??'draft')==='draft'?'selected':''?>>草稿</option><option value="published" <?=($article['status']??'')==='published'?'selected':''?>>发布</option></select></label><div class="form-actions"><button class="button">保存文章</button><?php if($article):?><a class="button secondary" target="_blank" href="<?=e(base_path('admin/articles/'.$article['id'].'/preview?surface=website'))?>">预览官网版本</a><a class="button secondary" target="_blank" href="<?=e(base_path('admin/articles/'.$article['id'].'/preview?surface=brochure'))?>">预览图册版本</a><?php endif?><a class="button secondary" href="<?=e(base_path('admin'))?>">返回后台</a></div></form></section></main><?php $this->layout($id?'编辑文章':'写文章',(string)ob_get_clean(),true);
    }

    private function articlePreview(int $id): void
    {
        $article=$this->articles->find($id);if(!$article)throw new RuntimeException('文章不存在。');
        if(($_GET['surface']??'website')==='brochure'){$this->renderBrochureArticle($article,true);return;}
        (new WebsiteApp())->renderArticle($article,true);
    }

    private function tutorialForm(string $method, ?int $id=null): void
    {
        $t=$id?$this->tutorials->find($id,true):null;if($id&&!$t)throw new RuntimeException('教程不存在。');
        if($method==='POST'){Auth::verifyCsrf();$saved=$this->tutorials->save($_POST,$_FILES,$id);$this->flash($id?'教程已更新。':'教程已添加。');header('Location: '.base_path('admin/tutorials/'.$saved.'/edit'));exit;}
        ob_start();?><main class="admin-shell"><?=$this->adminHeader($id?'编辑教程':'添加教程')?><section class="form-card"><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=e(Auth::csrf())?>"><label>教程标题<input name="title" maxlength="180" required value="<?=e($t['title']??'')?>"></label><label>一句话介绍<textarea name="description" maxlength="300" rows="3"><?=e($t['description']??'')?></textarea></label><label>文章正文<textarea name="body" rows="12"><?=e($t['body']??'')?></textarea><small>使用空行分段，前台会保留换行。</small></label><label>封面图片<input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label><label>人工排序值<input type="number" name="manual_priority" value="<?=(int)($t['manual_priority']??0)?>"></label><label class="check"><input type="checkbox" name="is_active" <?=!isset($t)||$t['is_active']?'checked':''?>> 在客户页面显示</label><fieldset class="media-fields"><legend>添加一个视频或文档（可选）</legend><label>类型<select name="media_type"><option value="">本次不添加</option><option value="video">视频</option><option value="document">文档</option></select></label><label>来源<select name="source_type"><option value="external">外部链接</option><option value="upload">自己上传</option></select></label><label>附件标题<input name="media_title" maxlength="180"></label><label>外部链接<input type="url" name="media_url" placeholder="https://"></label><label>上传文件<input type="file" name="media" accept="video/mp4,video/webm,application/pdf,.doc,.docx"><small>视频支持 MP4/WebM，文档支持 PDF/Word，单文件最大 500MB。</small></label><label>附件排序<input type="number" name="media_sort_order" value="0"></label></fieldset><div class="form-actions"><button class="button">保存<?= $id?'并添加附件':''?></button><a class="button secondary" href="<?=e(base_path('admin'))?>">返回后台</a></div></form></section><?php if($t&&$t['media']):?><section class="admin-section"><h2>现有附件</h2><div class="table-wrap"><table><tbody><?php foreach($t['media'] as $m):?><tr><td><?=e($m['title']?:'未命名附件')?></td><td><?=e($m['media_type']==='video'?'视频':'文档')?> · <?=e($m['source_type']==='upload'?'上传':'外链')?></td><td class="actions"><form method="post" action="<?=e(base_path('admin/tutorial-media/'.$m['id'].'/delete'))?>" onsubmit="return confirm('确定删除这个附件吗？')"><input type="hidden" name="_csrf" value="<?=e(Auth::csrf())?>"><button>删除</button></form></td></tr><?php endforeach?></tbody></table></div></section><?php endif?></main><?php $this->layout($id?'编辑教程':'添加教程',(string)ob_get_clean(),true);
    }

    private function categoryForm(string $method, ?int $id = null): void
    {
        $existing = null; foreach ($this->catalogs->categories(true) as $c) if ((int) $c['id'] === $id) $existing = $c;
        if ($method === 'POST') { Auth::verifyCsrf(); $this->catalogs->saveCategory($_POST, $id); $this->flash($id ? '分类已更新。' : '分类已添加。'); $this->redirectAdmin(); }
        ob_start(); ?>
        <main class="admin-shell"><?= $this->adminHeader($id ? '编辑分类' : '新增分类') ?><section class="form-card"><form method="post"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><label>分类名称<input name="name" maxlength="80" required value="<?= e($existing['name'] ?? '') ?>"></label><label>英文标识（可留空自动生成）<input name="slug" maxlength="100" value="<?= e($existing['slug'] ?? '') ?>"></label><label>排序值<input type="number" name="sort_order" value="<?= (int) ($existing['sort_order'] ?? 0) ?>"></label><label class="check"><input type="checkbox" name="is_active" <?= !isset($existing) || $existing['is_active'] ? 'checked' : '' ?>> 在前台显示</label><div class="form-actions"><button class="button" type="submit">保存分类</button><a class="button secondary" href="<?= e(base_path('admin')) ?>">取消</a></div></form></section></main>
        <?php $this->layout($id ? '编辑分类' : '新增分类', (string) ob_get_clean(), true);
    }

    private function catalogNew(string $method): void
    {
        $preview = null; $error = '';
        if ($method === 'POST') {
            Auth::verifyCsrf();
            try {
                if (($_POST['action'] ?? '') === 'save') { $id = $this->catalogs->create($_POST); $this->flash('图册已添加。'); $this->redirectAdmin(); }
                $preview = $this->catalogs->preview((string) ($_POST['source_url'] ?? ''));
                $_POST['name'] = $_POST['name'] ?: $preview['title']; $_POST['description'] = $_POST['description'] ?: $preview['description'];
            } catch (\Throwable $e) { $error = $e->getMessage(); }
        }
        $this->catalogForm(null, $preview, $error);
    }

    private function catalogEdit(string $method, int $id): void
    {
        $catalog = $this->catalogs->find($id, true); if (!$catalog) throw new RuntimeException('图册不存在。');
        if ($method === 'POST') { Auth::verifyCsrf(); $this->catalogs->update($id, $_POST, $_FILES['cover'] ?? null); $this->flash('图册资料已更新。'); $this->redirectAdmin(); }
        $this->catalogForm($catalog);
    }

    private function catalogForm(?array $catalog = null, ?array $preview = null, string $error = ''): void
    {
        $categories = $this->catalogs->categories(true); $editing = $catalog !== null;
        $flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
        ob_start(); ?>
        <main class="admin-shell"><?= $this->adminHeader($editing ? '编辑图册' : '添加图册') ?><?php if ($flash): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><strong>解析未完成</strong><p><?= e($error) ?></p></div><?php endif; ?><?php if ($preview): ?><div class="parse-preview"><img src="<?= e($preview['cover_url']) ?>" alt="解析到的封面"><div><span class="status ok">解析成功 · <?= count($preview['pages']) ?> 页</span><h2><?= e($preview['title']) ?></h2><p><?= e($preview['description']) ?></p><small><?= e($this->sourceLabel($preview['source_type'])) ?></small></div></div><?php endif; ?>
        <?php if ($editing): ?><section class="local-pages-panel"><div><strong>本地高清页面</strong><p><?= (int) $catalog['local_page_count'] > 0 ? '已生成 ' . (int) $catalog['local_page_count'] . ' 张，可选择本地图片阅读。' : '尚未生成；当前只能使用原网页阅读。' ?></p></div><form method="post" action="<?= e(base_path('admin/catalogs/' . $catalog['id'] . '/local-pages')) ?>"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><button class="button secondary" type="submit"><?= (int) $catalog['local_page_count'] > 0 ? '重新生成本地图片' : '生成本地图片' ?></button></form></section><?php endif; ?>
        <section class="form-card"><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><?php if (!$editing): ?><label>电子图册链接<input type="url" name="source_url" required value="<?= e($_POST['source_url'] ?? '') ?>" placeholder="粘贴云展网、goootu 或 FLBOOK 链接"></label><?php else: ?><div class="readonly-field"><span>来源链接</span><code><?= e($catalog['source_url']) ?></code></div><label>前端阅读方式<select name="reader_mode"><option value="source" <?= $catalog['reader_mode'] === 'source' ? 'selected' : '' ?>>原网页直接显示</option><option value="local" <?= $catalog['reader_mode'] === 'local' ? 'selected' : '' ?> <?= (int) $catalog['local_page_count'] < 1 ? 'disabled' : '' ?>>本地高清图片<?= (int) $catalog['local_page_count'] < 1 ? '（请先生成）' : '' ?></option></select><small>本地图片阅读加载更稳定；重新解析图册后需重新生成。</small></label><?php endif; ?><label>分类<select name="category_id" required><option value="">请选择</option><?php foreach ($categories as $c): $selected=(int)($catalog['category_id'] ?? $_POST['category_id'] ?? 0)===(int)$c['id']; ?><option value="<?= $c['id'] ?>" <?= $selected?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>图册名称<input name="name" maxlength="180" <?= $preview || $editing ? 'required' : '' ?> value="<?= e($catalog['name'] ?? $_POST['name'] ?? '') ?>"></label><label>一句话介绍<textarea name="description" maxlength="300" rows="3"><?= e($catalog['description'] ?? $_POST['description'] ?? '') ?></textarea></label><label>人工排序值<input type="number" name="manual_priority" value="<?= (int)($catalog['manual_priority'] ?? $_POST['manual_priority'] ?? 0) ?>"><small>数值越大越靠前；相同数值再按热度。</small></label><?php if ($editing): ?><label>手工替换封面<input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label><?php endif; ?><label class="check"><input type="checkbox" name="is_active" <?= !isset($catalog) || $catalog['is_active'] ? 'checked' : '' ?>> 在客户页面显示</label><div class="form-actions"><?php if ($editing): ?><button class="button" type="submit">保存修改</button><?php elseif ($preview): ?><button class="button" type="submit" name="action" value="save">确认添加</button><button class="button secondary" type="submit" name="action" value="preview">重新解析</button><?php else: ?><button class="button" type="submit" name="action" value="preview">解析链接</button><?php endif; ?><a class="button secondary" href="<?= e(base_path('admin')) ?>">取消</a></div></form></section></main>
        <?php $this->layout($editing ? '编辑图册' : '添加图册', (string) ob_get_clean(), true);
    }

    private function download(int $id): void
    {
        $file = $this->catalogs->buildDownload($id);
        header('Content-Type: ' . (str_ends_with($file, '.pdf') ? 'application/pdf' : 'application/zip'));
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode(basename($file)));
        header('Content-Length: ' . filesize($file)); readfile($file); exit;
    }

    private function dataManagement(): void
    {
        $flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
        ob_start(); ?>
        <main class="admin-shell"><?= $this->adminHeader('数据管理') ?><?php if ($flash): ?><div class="notice success"><?= e($flash) ?></div><?php endif; ?>
            <section class="admin-section data-panel"><div><h2>导出 ZIP 备份</h2><p>分类、图册资料、热度记录和封面始终备份；导出时可选择是否包含本地高清页面。</p></div><form method="post" action="<?= e(base_path('admin/data/export')) ?>" onsubmit="this.elements.include_local_pages.value=confirm('是否同时备份本地高清图片？\n\n确定：包含本地高清图片\n取消：只备份数据和封面')?'1':'0';"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><input type="hidden" name="include_local_pages" value="1"><button class="button" type="submit">导出 ZIP 备份</button></form></section>
            <section class="admin-section data-panel"><div><h2>导入并恢复</h2><p>导入会替换当前分类和图册数据，并恢复备份中的封面与本地高清页面。建议先导出当前备份。</p></div><form class="import-form" method="post" action="<?= e(base_path('admin/data/import')) ?>" enctype="multipart/form-data" onsubmit="return confirm('确定用导入文件替换当前图册数据吗？')"><input type="hidden" name="_csrf" value="<?= e(Auth::csrf()) ?>"><input type="file" name="data_file" accept="application/zip,.zip" required><label class="check"><input type="checkbox" name="confirm_replace" value="1" required> 我确认替换当前数据</label><button class="button" type="submit">导入 ZIP 备份</button></form></section>
        </main><?php $this->layout('数据管理', (string) ob_get_clean(), true);
    }

    private function exportData(): never
    {
        $file = (new DataManager(Database::connection()))->createBackupZip(($_POST['include_local_pages'] ?? '1') === '1');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file); @unlink($file); exit;
    }

    private function importData(): never
    {
        if (($_POST['confirm_replace'] ?? '') !== '1') throw new RuntimeException('请确认替换当前数据。');
        $file = $_FILES['data_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('请选择有效的数据文件。');
        $temporary=(string)($file['tmp_name']??'');$size=(int)($file['size']??0);
        if($size<1||$size>2*1024*1024*1024||!is_uploaded_file($temporary))throw new RuntimeException('备份文件大小或来源无效。');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($temporary);if(!in_array($mime,['application/zip','application/x-zip-compressed','application/octet-stream'],true))throw new RuntimeException('仅支持 ZIP 备份文件。');
        $result = (new DataManager(Database::connection()))->importBackupZip($temporary);
        $this->flash('导入完成：' . $result['categories'] . ' 个分类，' . $result['catalogs'] . ' 本图册，' . ($result['tutorials']??0) . ' 篇教程，' . $result['local_pages'] . ' 张本地高清页面。');
        header('Location: ' . base_path('admin/data')); exit;
    }

    private function adminHeader(string $title): string
    {
        return '<header class="admin-header"><div><a class="brand-placeholder" href="' . e(base_path('admin')) . '"><span class="brand-cn">乐宅.Life</span></a><h1>' . e($title) . '</h1></div><nav><a href="/" target="_blank">查看官网</a><a href="/brochure" target="_blank">查看图册</a><a href="' . e(base_path('admin/data')) . '">数据管理</a><form method="post" action="' . e(base_path('admin/logout')) . '"><input type="hidden" name="_csrf" value="' . e(Auth::csrf()) . '"><button type="submit">退出</button></form></nav></header>';
    }

    private function layout(string $title, string $content, bool $admin = false, string $head = ''): void
    {
        ?><!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#C65D3B"><title><?= e($title) ?>｜乐宅.Life</title><?=$head?><link rel="stylesheet" href="<?= e(base_path('assets/app.css')) ?>"><link rel="stylesheet" href="<?= e(base_path('assets/mobile-fixes.css')) ?>"></head><body class="<?= $admin ? 'admin-body' : 'public-body' ?>"><?= $content ?><footer class="app-copyright">© 2026 乐宅.Life</footer><script src="<?= e(base_path('assets/app.js')) ?>" defer></script></body></html><?php
    }

    private function sourceLabel(string $source): string { return ['yunzhan365'=>'云展网','goootu'=>'goootu','flbook'=>'FLBOOK'][$source] ?? $source; }
    private function flash(string $message): void { $_SESSION['flash'] = $message; }
    private function redirectAdmin(): never { header('Location: ' . base_path('admin')); exit; }
    private function json(array $data): never { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
}
