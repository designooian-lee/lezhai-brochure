<?php
declare(strict_types=1);

namespace Lezhai;

final class WebsiteApp
{
    private ArticleService $articles;
    public function __construct(){ $this->articles=new ArticleService(Database::connection()); }

    public function run(string $path): void
    {
        if($path==='/health'){Database::connection()->query('SELECT 1');header('Content-Type: application/json; charset=utf-8');echo '{"status":"ok"}';return;}
        if($path==='/robots.txt'){$this->robots();return;}if($path==='/sitemap.xml'){$this->sitemap();return;}
        if($path==='/articles'||$path==='/articles/'){$this->articleList();return;}
        if(preg_match('~^/articles/([a-z0-9-]+)/?$~',$path,$match)){$article=$this->articles->findBySlug($match[1]);if($article){$this->renderArticle($article);return;}$this->notFound('文章不存在或尚未发布。');return;}
        if($path==='/'||preg_match('~^/(services|cooperation|about|contact)/?$~',$path)){$this->staticPage($path);return;}
        $this->notFound('没有找到这个页面。');
    }

    public function renderArticle(array $article,bool $preview=false): void
    {
        if(!$preview)$this->articles->recordMonthlyView((int)$article['id']);
        $title=(string)($article['seo_title']?:$article['title']);$description=(string)($article['meta_description']?:$article['excerpt']);$neighbors=$this->articles->neighbors($article);
        $date=$article['published_at']?date('Y年n月j日',strtotime((string)$article['published_at'])):'草稿预览';
        $neighborHtml='<nav class="website-article-neighbors" aria-label="相邻文章">';foreach(['previous'=>'上一篇','next'=>'下一篇']as$key=>$label){$item=$neighbors[$key]??null;if($item)$neighborHtml.='<a class="'.$key.'" href="/articles/'.e($item['slug']).'"><span>'.$label.'</span><strong>'.e($item['title']).'</strong></a>';}$neighborHtml.='</nav>';
        $hotLinks='';foreach($this->articles->monthlyHot((int)$article['id'],10)as$hot)$hotLinks.='<a href="/articles/'.e($hot['slug']).'"><span>'.e($hot['title']).'</span><small>'.(int)$hot['monthly_views'].' 次阅读</small></a>';
        $hotHtml=$hotLinks===''?'<div class="article-hot-viewport"><p class="article-hot-empty">暂无其他文章</p></div>':'<div class="article-hot-viewport"><div class="article-hot-track">'.$hotLinks.'<div aria-hidden="true" class="article-hot-copy">'.$hotLinks.'</div></div></div>';
        $content='<section class="content-hero"><div class="container content-hero-box"><p class="eyebrow">LEZHAI JOURNAL</p><h1 class="page-title">'.e($article['title']).'</h1><p class="lead">'.e($article['excerpt']).'</p><p class="article-date">'.e($date).'</p></div></section><section class="section"><div class="container content-grid"><article class="content-main prose website-richtext">'.$article['body_html'].$neighborHtml.'</article><aside class="content-side info-panel"><img src="/brand/logo-signature.png" alt="乐宅.Life，把喜欢的门，装进生活"><dl><dt>文章状态</dt><dd>'.($preview?'管理员预览':'已发布').'</dd><dt>更新时间</dt><dd>'.e(date('Y-m-d',strtotime((string)$article['updated_at']))).'</dd></dl><section class="article-hot" aria-labelledby="article-hot-title"><h2 id="article-hot-title">热门文章</h2>'.$hotHtml.'</section></aside></div></section>';
        $schema=['@context'=>'https://schema.org','@type'=>'Article','headline'=>$article['title'],'description'=>$description,'datePublished'=>$article['published_at'],'dateModified'=>$article['updated_at'],'mainEntityOfPage'=>'https://lezhai.life/articles/'.$article['slug'],'publisher'=>['@type'=>'Organization','name'=>'乐宅.Life']];
        $this->dynamicShell($title,$description,$content,'/articles/'.$article['slug'],$article['cover_path']?:'/images/og-lezhai.jpg',$schema,$preview,(string)($article['seo_keywords']??''));
    }

    private function articleList(): void
    {
        $result=$this->articles->page((int)($_GET['page']??1),6);$cards='';foreach($result['items']as$article){$cover=$article['cover_path']?'<img src="'.e($article['cover_path']).'" alt="'.e($article['title']).'">':'<span class="article-card-placeholder" aria-hidden="true"></span>';$cards.='<article class="website-article-card"><a href="/articles/'.e($article['slug']).'">'.$cover.'<div><time>'.e(date('Y.m.d',strtotime((string)$article['published_at']))).'</time><h2>'.e($article['title']).'</h2><p>'.e($article['excerpt']).'</p><span>阅读全文 →</span></div></a></article>';}
        if($cards==='')$cards='<p class="website-empty-card">文章正在整理中，欢迎稍后再来。</p>';
        $pagination=$this->pagination('/articles',$result['page'],$result['pages']);$content='<section class="content-hero"><div class="container content-hero-box"><p class="eyebrow">LEZHAI JOURNAL</p><h1 class="page-title">把门窗选择，说清楚</h1><p class="lead">关于设计、现场、安装与日常使用的具体记录。</p></div></section><section class="section"><div class="container"><div class="website-article-grid">'.$cards.'</div>'.$pagination.'</div></section>';
        $path='/articles'.($result['page']>1?'?page='.$result['page']:'');$this->dynamicShell('文章｜乐宅.Life','乐宅.Life 门窗设计、量尺、安装与使用知识。',$content,$path);
    }

    private function dynamicShell(string $title,string $description,string $content,string $path,string $image='/images/og-lezhai.jpg',?array $schema=null,bool $preview=false,string $keywords=''): void
    {
        $file=dirname(__DIR__).'/storage/website-dist/article-shell/index.html';if(!is_file($file)){http_response_code(503);echo '官网静态资源尚未构建，请先运行 pnpm run build:website。';return;}
        $html=(string)file_get_contents($file);$canonical='https://lezhai.life'.$path;$html=str_replace('__ARTICLE_TITLE__',e($title),$html);$html=str_replace('__ARTICLE_DESCRIPTION__',e($description),$html);$html=str_replace('<div data-dynamic-article-content></div>',$content,$html);
        $html=preg_replace('~<link rel="canonical" href="[^"]+">~','<link rel="canonical" href="'.e($canonical).'">',$html,1)??$html;
        $html=preg_replace('~<meta property="og:url" content="[^"]+">~','<meta property="og:url" content="'.e($canonical).'">',$html,1)??$html;
        $html=preg_replace('~<meta property="og:image" content="[^"]+">~','<meta property="og:image" content="'.e('https://lezhai.life'.$image).'">',$html,1)??$html;
        if($keywords!=='')$html=str_replace('</head>','<meta name="keywords" content="'.e($keywords).'"></head>',$html);
        if($schema)$html=str_replace('</head>','<script type="application/ld+json">'.json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'</script></head>',$html);
        if($preview)$html=preg_replace('~<meta name="robots" content="[^"]+">~','<meta name="robots" content="noindex,nofollow">',$html,1)??$html;
        echo $this->injectLatestArticles($html);
    }

    private function staticPage(string $path): void
    {
        $relative=trim($path,'/');$file=dirname(__DIR__).'/storage/website-dist/'.($relative===''?'index.html':$relative.'/index.html');if(!is_file($file)){http_response_code(503);echo '官网静态资源尚未构建，请先运行 pnpm run build:website。';return;}echo $this->injectLatestArticles((string)file_get_contents($file));
    }

    private function injectLatestArticles(string $html): string
    {
        $links='';foreach($this->articles->all(false,4)as$article)$links.='<a href="/articles/'.e($article['slug']).'"><span aria-hidden="true">•</span>'.e($article['title']).'</a>';
        if($links==='')$links='<span class="footer-articles-empty">文章正在整理中</span>';
        return str_replace('<span data-latest-articles></span>','<span class="footer-latest-articles">'.$links.'</span>',$html);
    }

    private function pagination(string $path,int $current,int $pages): string
    {
        if($pages<=1)return '';$html='<nav class="website-pagination" aria-label="文章分页">';for($page=1;$page<=$pages;$page++)$html.='<a class="'.($page===$current?'active':'').'" '.($page===$current?'aria-current="page" ':'').'href="'.$path.($page>1?'?page='.$page:'').'">'.$page.'</a>';return $html.'</nav>';
    }

    private function notFound(string $message): void{http_response_code(404);$this->dynamicShell('页面不存在｜乐宅.Life',$message,'<section class="not-found"><div><strong>404</strong><h1>页面不存在</h1><p>'.e($message).'</p><a class="button" href="/">返回官网首页</a></div></section>','/404');}
    private function robots():void{header('Content-Type: text/plain; charset=utf-8');echo "User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: https://lezhai.life/sitemap.xml\n";}
    private function sitemap():void{header('Content-Type: application/xml; charset=utf-8');$urls=['/','/services/','/cooperation/','/about/','/contact/','/articles'];foreach($this->articles->all()as$a)$urls[]='/articles/'.$a['slug'];echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($urls as$url)echo '<url><loc>https://lezhai.life'.e($url).'</loc></url>';echo '</urlset>';}
}
