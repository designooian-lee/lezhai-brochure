<?php
declare(strict_types=1);

namespace Lezhai;

use DOMDocument;
use DOMElement;
use PDO;
use RuntimeException;

final class ArticleService
{
    private const MAX_UPLOAD = 8 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;

    public function __construct(private readonly PDO $pdo) {}

    public function all(bool $includeDrafts = false, ?int $limit = null): array
    {
        $sql = 'SELECT * FROM articles' . ($includeDrafts ? '' : " WHERE status='published' AND published_at<=NOW()") . ' ORDER BY published_at DESC NULLS LAST,id DESC';
        if ($limit !== null) $sql .= ' LIMIT ' . max(1, $limit);
        return $this->pdo->query($sql)->fetchAll();
    }

    public function page(int $page, int $perPage, bool $includeDrafts = false): array
    {
        $where = $includeDrafts ? '' : " WHERE status='published' AND published_at<=NOW()";
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM articles'.$where)->fetchColumn();
        $pages = max(1, (int)ceil($total / $perPage)); $page = min(max(1, $page), $pages);
        $statement=$this->pdo->prepare('SELECT * FROM articles'.$where.' ORDER BY published_at DESC NULLS LAST,id DESC LIMIT ? OFFSET ?');
        $statement->bindValue(1,$perPage,PDO::PARAM_INT);$statement->bindValue(2,($page-1)*$perPage,PDO::PARAM_INT);$statement->execute();
        return ['items'=>$statement->fetchAll(),'page'=>$page,'pages'=>$pages,'total'=>$total];
    }

    public function findBySlug(string $slug, bool $includeDrafts = false): ?array
    {
        $sql = 'SELECT * FROM articles WHERE slug=?' . ($includeDrafts ? '' : " AND status='published' AND published_at<=NOW()");
        $statement = $this->pdo->prepare($sql); $statement->execute([$slug]);
        return $statement->fetch() ?: null;
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM articles WHERE id=?'); $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public function neighbors(array $article): array
    {
        if(($article['status']??'')!=='published'||empty($article['published_at']))return ['previous'=>null,'next'=>null];
        $previous=$this->pdo->prepare("SELECT * FROM articles WHERE status='published' AND published_at<=NOW() AND (published_at,id)>(?,?) ORDER BY published_at,id LIMIT 1");
        $next=$this->pdo->prepare("SELECT * FROM articles WHERE status='published' AND published_at<=NOW() AND (published_at,id)<(?,?) ORDER BY published_at DESC,id DESC LIMIT 1");
        $arguments=[$article['published_at'],(int)$article['id']];$previous->execute($arguments);$next->execute($arguments);
        return ['previous'=>$previous->fetch()?:null,'next'=>$next->fetch()?:null];
    }

    public function recordMonthlyView(int $articleId): void
    {
        $statement=$this->pdo->prepare("INSERT INTO article_monthly_views(article_id,viewed_month,view_count,updated_at) VALUES(?,date_trunc('month',CURRENT_DATE)::date,1,NOW()) ON CONFLICT(article_id,viewed_month) DO UPDATE SET view_count=article_monthly_views.view_count+1,updated_at=NOW()");
        $statement->execute([$articleId]);
    }

    public function monthlyHot(int $excludeId, int $limit = 10): array
    {
        $statement=$this->pdo->prepare("SELECT a.id,a.slug,a.title,COALESCE(v.view_count,0) AS monthly_views FROM articles a LEFT JOIN article_monthly_views v ON v.article_id=a.id AND v.viewed_month=date_trunc('month',CURRENT_DATE)::date WHERE a.status='published' AND a.published_at<=NOW() AND a.id<>? ORDER BY COALESCE(v.view_count,0) DESC,RANDOM() LIMIT ?");
        $statement->bindValue(1,$excludeId,PDO::PARAM_INT);$statement->bindValue(2,max(1,$limit),PDO::PARAM_INT);$statement->execute();
        return $statement->fetchAll();
    }

    public function save(array $input, ?array $cover, ?int $id = null): int
    {
        $title=trim((string)($input['title']??''));if($title==='')throw new RuntimeException('请填写文章标题。');
        $existing=$id?$this->find($id):null;if($id&&!$existing)throw new RuntimeException('文章不存在。');
        $requestedSlug=$this->normalizeSlug((string)($input['slug']??''));
        $slug=$requestedSlug!==''?$requestedSlug:(string)($existing['slug']??('pending-'.bin2hex(random_bytes(8))));
        $duplicate=$this->pdo->prepare('SELECT id FROM articles WHERE slug=? AND id<>?');$duplicate->execute([$slug,$id??0]);
        if($duplicate->fetch())throw new RuntimeException('这个文章网址标识已被使用。');
        $body=$this->sanitizeHtml((string)($input['body_html']??''));$newFiles=[];
        try{
            $coverPath=(string)($existing['cover_path']??'');
            $oldCover=$coverPath;
            if(isset($input['remove_cover']))$coverPath='';
            if($cover&&($cover['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$coverPath=$this->storeImage($cover,'cover',true);$newFiles[]=$coverPath;}
            if($coverPath===''&&!isset($input['remove_cover'])){$first=$this->firstBodyImage($body);if($first!==''){$coverPath=$this->coverFromStoredImage($first);$newFiles[]=$coverPath;}}
            $status=($input['status']??'draft')==='published'?'published':'draft';
            $publishedAt=$status==='published'?($existing['published_at']??date(DATE_ATOM)):null;
            $values=[$title,$slug,trim((string)($input['excerpt']??'')),$body,$coverPath,trim((string)($input['seo_title']??'')),trim((string)($input['seo_keywords']??'')),trim((string)($input['meta_description']??'')),$status,$publishedAt];
            $this->pdo->beginTransaction();
            if($id){$values[]=$id;$this->pdo->prepare('UPDATE articles SET title=?,slug=?,excerpt=?,body_html=?,cover_path=?,seo_title=?,seo_keywords=?,meta_description=?,status=?,published_at=?,updated_at=NOW() WHERE id=?')->execute($values);$saved=$id;}
            else{$statement=$this->pdo->prepare('INSERT INTO articles(title,slug,excerpt,body_html,cover_path,seo_title,seo_keywords,meta_description,status,published_at) VALUES(?,?,?,?,?,?,?,?,?,?) RETURNING id');$statement->execute($values);$saved=(int)$statement->fetchColumn();if($requestedSlug===''){$slug='article-'.$saved;$this->pdo->prepare('UPDATE articles SET slug=? WHERE id=?')->execute([$slug,$saved]);}}
            $this->pdo->commit();
            if($oldCover!==$coverPath&&str_starts_with($oldCover,'/uploads/articles/')&&!str_contains($body,'src="'.$oldCover.'"'))@unlink(dirname(__DIR__).'/public'.$oldCover);
            return $saved;
        }catch(\Throwable $exception){if($this->pdo->inTransaction())$this->pdo->rollBack();foreach($newFiles as $path)@unlink(dirname(__DIR__).'/public'.$path);throw $exception;}
    }

    public function uploadBodyImage(array $file): string { return $this->storeImage($file,'body',false); }

    public function delete(int $id): void
    {
        $article=$this->find($id);if(!$article)return;$this->pdo->prepare('DELETE FROM articles WHERE id=?')->execute([$id]);
        $paths=[(string)$article['cover_path']];if(preg_match_all('~<img\s+[^>]*src="(/uploads/articles/[A-Za-z0-9._-]+)"~i',(string)$article['body_html'],$matches))$paths=array_merge($paths,$matches[1]);
        $referenced=$this->pdo->prepare('SELECT 1 FROM articles WHERE cover_path = ? OR body_html LIKE ? LIMIT 1');
        foreach(array_unique($paths)as$path){
            if(!str_starts_with($path,'/uploads/articles/'))continue;
            $referenced->execute([$path,'%src="'.$path.'"%']);
            if(!$referenced->fetchColumn())@unlink(dirname(__DIR__).'/public'.$path);
        }
    }

    private function normalizeSlug(string $slug): string
    {
        $slug=strtolower(trim($slug));$slug=preg_replace('~[^a-z0-9]+~','-',$slug)??'';return trim($slug,'-');
    }

    private function storeImage(array $file,string $prefix,bool $cover): string
    {
        if(!extension_loaded('gd'))throw new RuntimeException('服务器未启用 GD 图片处理扩展。');
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('图片上传失败。');
        if((int)($file['size']??0)<1||(int)$file['size']>self::MAX_UPLOAD)throw new RuntimeException('图片大小必须在 8MB 以内。');
        $temporary=(string)($file['tmp_name']??'');if(!is_uploaded_file($temporary))throw new RuntimeException('图片上传来源无效。');
        $extension=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));if($extension!==''&&!in_array($extension,['jpg','jpeg','png','webp','gif'],true))throw new RuntimeException('图片扩展名不受支持。');
        $info=@getimagesize($temporary);$mime=(string)($info['mime']??'');
        if(!$info||!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true))throw new RuntimeException('仅支持可正常解码的 JPG、PNG、WebP 或 GIF 图片。');
        $width=(int)$info[0];$height=(int)$info[1];if($width<1||$height<1||$width*$height>self::MAX_PIXELS)throw new RuntimeException('图片像素尺寸过大。');
        $source=$this->decodeImage($temporary,$mime);if(!$source)throw new RuntimeException('图片无法解码。');
        $target=$cover?$this->makeCover($source,$width,$height):$this->resizeBody($source,$width,$height);imagedestroy($source);
        $directory=dirname(__DIR__).'/public/uploads/articles';@mkdir($directory,0775,true);$name=$prefix.'-'.bin2hex(random_bytes(12)).'.webp';$final=$directory.'/'.$name;$working=$final.'.tmp';
        if(!imagewebp($target,$working,82)){imagedestroy($target);@unlink($working);throw new RuntimeException('图片压缩失败。');}imagedestroy($target);
        if(!@rename($working,$final)){@unlink($working);throw new RuntimeException('图片保存失败。');}return '/uploads/articles/'.$name;
    }

    private function coverFromStoredImage(string $path): string
    {
        $file=realpath(dirname(__DIR__).'/public'.$path);$root=realpath(dirname(__DIR__).'/public/uploads/articles');
        if($file===false||$root===false||!str_starts_with($file,$root.DIRECTORY_SEPARATOR))return '';
        $info=@getimagesize($file);if(!$info)return '';$source=$this->decodeImage($file,(string)$info['mime']);if(!$source)return '';
        $target=$this->makeCover($source,(int)$info[0],(int)$info[1]);imagedestroy($source);$directory=dirname(__DIR__).'/public/uploads/articles';$name='cover-'.bin2hex(random_bytes(12)).'.webp';$working=$directory.'/'.$name.'.tmp';
        if(!imagewebp($target,$working,82)){imagedestroy($target);@unlink($working);throw new RuntimeException('自动封面生成失败。');}imagedestroy($target);if(!@rename($working,$directory.'/'.$name)){@unlink($working);throw new RuntimeException('自动封面保存失败。');}return '/uploads/articles/'.$name;
    }

    private function decodeImage(string $file,string $mime): \GdImage|false
    {
        return match($mime){'image/jpeg'=>@imagecreatefromjpeg($file),'image/png'=>@imagecreatefrompng($file),'image/webp'=>@imagecreatefromwebp($file),'image/gif'=>@imagecreatefromgif($file),default=>false};
    }

    private function makeCover(\GdImage $source,int $width,int $height): \GdImage
    {
        $target=imagecreatetruecolor(500,670);$sourceRatio=$width/$height;$targetRatio=500/670;
        if($sourceRatio>$targetRatio){$cropHeight=$height;$cropWidth=(int)round($height*$targetRatio);$sourceX=(int)(($width-$cropWidth)/2);$sourceY=0;}else{$cropWidth=$width;$cropHeight=(int)round($width/$targetRatio);$sourceX=0;$sourceY=(int)(($height-$cropHeight)/2);}
        imagecopyresampled($target,$source,0,0,$sourceX,$sourceY,500,670,$cropWidth,$cropHeight);return $target;
    }

    private function resizeBody(\GdImage $source,int $width,int $height): \GdImage
    {
        $scale=min(1,1920/max($width,$height));$newWidth=max(1,(int)round($width*$scale));$newHeight=max(1,(int)round($height*$scale));$target=imagecreatetruecolor($newWidth,$newHeight);imagealphablending($target,false);imagesavealpha($target,true);$transparent=imagecolorallocatealpha($target,0,0,0,127);imagefill($target,0,0,$transparent);imagecopyresampled($target,$source,0,0,0,0,$newWidth,$newHeight,$width,$height);return $target;
    }

    private function firstBodyImage(string $html): string
    {
        return preg_match('~<img\s+[^>]*src="(/uploads/articles/[A-Za-z0-9._-]+)"~i',$html,$match)?$match[1]:'';
    }

    private function sanitizeHtml(string $html): string
    {
        if(trim($html)==='')return '';$document=new DOMDocument('1.0','UTF-8');libxml_use_internal_errors(true);$document->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>',LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);libxml_clear_errors();$allowed=['div','p','h2','h3','ul','ol','li','strong','b','em','i','a','img','blockquote','br'];
        $walk=function($node)use(&$walk,$allowed):void{foreach(iterator_to_array($node->childNodes)as$child){if(!$child instanceof DOMElement)continue;$tag=strtolower($child->tagName);if(!in_array($tag,$allowed,true)){if(!in_array($tag,['script','style','iframe','object','svg','math'],true))while($child->firstChild)$child->parentNode?->insertBefore($child->firstChild,$child);$child->parentNode?->removeChild($child);continue;}foreach(iterator_to_array($child->attributes)as$attribute){$name=strtolower($attribute->name);$keep=($tag==='a'&&in_array($name,['href','title'],true))||($tag==='img'&&in_array($name,['src','alt'],true));if(!$keep)$child->removeAttribute($attribute->name);}if($tag==='a'&&!preg_match('~^(https?://|/|#)~i',$child->getAttribute('href')))$child->removeAttribute('href');if($tag==='img'&&!preg_match('~^/uploads/articles/[A-Za-z0-9._-]+$~',$child->getAttribute('src')))$child->parentNode?->removeChild($child);else$walk($child);}};$walk($document);$root=$document->documentElement;$result='';foreach(iterator_to_array($root?->childNodes??[])as$child)$result.=$document->saveHTML($child);return trim($result);
    }
}
