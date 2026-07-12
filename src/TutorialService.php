<?php
declare(strict_types=1);
namespace Lezhai;

use PDO;
use RuntimeException;

final class TutorialService
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(bool $admin = false): array
    {
        $sql = 'SELECT t.*, COUNT(m.id) AS media_count FROM tutorials t LEFT JOIN tutorial_media m ON m.tutorial_id=t.id';
        if (!$admin) $sql .= ' WHERE t.is_active';
        $sql .= ' GROUP BY t.id ORDER BY t.manual_priority DESC,t.id DESC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function find(int $id, bool $admin = false): ?array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM tutorials WHERE id=?' . ($admin ? '' : ' AND is_active'));
        $stmt->execute([$id]); $row=$stmt->fetch();
        if (!$row) return null;
        $media=$this->pdo->prepare('SELECT * FROM tutorial_media WHERE tutorial_id=? ORDER BY sort_order DESC,id');
        $media->execute([$id]); $row['media']=$media->fetchAll(); return $row;
    }

    public function save(array $input, array $files, ?int $id=null): int
    {
        $title=trim((string)($input['title']??''));
        if ($title==='') throw new RuntimeException('教程标题不能为空。');
        $existing=$id ? $this->find($id,true) : null;
        if ($id && !$existing) throw new RuntimeException('教程不存在。');
        $cover=$existing['cover_path']??'';
        if (($files['cover']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK) $cover=$this->upload($files['cover'],'cover',['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'],10*1024*1024);
        $values=[$title,trim((string)($input['description']??'')),trim((string)($input['body']??'')),$cover,(int)($input['manual_priority']??0),isset($input['is_active'])];
        if ($id) { $values[]=$id; $this->pdo->prepare('UPDATE tutorials SET title=?,description=?,body=?,cover_path=?,manual_priority=?,is_active=?,updated_at=NOW() WHERE id=?')->execute($values); }
        else { $stmt=$this->pdo->prepare('INSERT INTO tutorials(title,description,body,cover_path,manual_priority,is_active) VALUES(?,?,?,?,?,?) RETURNING id'); $stmt->execute($values); $id=(int)$stmt->fetchColumn(); }
        $kind=(string)($input['media_type']??''); $source=(string)($input['source_type']??'');
        if (in_array($kind,['video','document'],true) && in_array($source,['external','upload'],true)) {
            $url=''; $path=''; $mime='';
            if ($source==='external') { $url=trim((string)($input['media_url']??'')); if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)) throw new RuntimeException('请输入有效的 HTTPS/HTTP 媒体链接。'); }
            else { $allowed=$kind==='video' ? ['video/mp4'=>'mp4','video/webm'=>'webm'] : ['application/pdf'=>'pdf','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx']; $path=$this->upload($files['media']??[],'media',$allowed,500*1024*1024); $mime=(new \finfo(FILEINFO_MIME_TYPE))->file(dirname(__DIR__).'/public'.$path)?:''; }
            $this->pdo->prepare('INSERT INTO tutorial_media(tutorial_id,media_type,source_type,title,url,file_path,mime_type,sort_order) VALUES(?,?,?,?,?,?,?,?)')->execute([$id,$kind,$source,trim((string)($input['media_title']??'')),$url,$path,$mime,(int)($input['media_sort_order']??0)]);
        }
        return $id;
    }

    public function deleteMedia(int $id): void { $s=$this->pdo->prepare('SELECT file_path FROM tutorial_media WHERE id=?');$s->execute([$id]);$p=(string)$s->fetchColumn();$this->pdo->prepare('DELETE FROM tutorial_media WHERE id=?')->execute([$id]);if(str_starts_with($p,'/uploads/tutorials/'))@unlink(dirname(__DIR__).'/public'.$p); }
    public function delete(int $id): void { $t=$this->find($id,true);if(!$t)return;foreach($t['media'] as $m)$this->deleteMedia((int)$m['id']);if(str_starts_with((string)$t['cover_path'],'/uploads/tutorials/'))@unlink(dirname(__DIR__).'/public'.$t['cover_path']);$this->pdo->prepare('DELETE FROM tutorials WHERE id=?')->execute([$id]); }

    private function upload(array $file,string $prefix,array $allowed,int $max): string
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('请选择要上传的文件。');
        $size=(int)($file['size']??0);$temporary=(string)($file['tmp_name']??'');
        if ($size<1 || $size>$max) throw new RuntimeException('上传文件大小无效或超过限制。');
        if(!is_uploaded_file($temporary))throw new RuntimeException('上传文件来源无效。');
        $type=(new \finfo(FILEINFO_MIME_TYPE))->file($temporary);if(!isset($allowed[$type]))throw new RuntimeException('不支持这种文件格式。');
        $dir=dirname(__DIR__).'/public/uploads/tutorials';@mkdir($dir,0775,true);$name=$prefix.'-'.bin2hex(random_bytes(12)).'.'.$allowed[$type];if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))throw new RuntimeException('文件上传失败。');return '/uploads/tutorials/'.$name;
    }
}
