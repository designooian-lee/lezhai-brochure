<?php
declare(strict_types=1);

namespace Lezhai;

use PDO;
use RuntimeException;

final class CatalogJobService
{
    public function __construct(private readonly PDO $pdo) {}

    public function enqueue(int $catalogId): array
    {
        $this->pdo->beginTransaction();
        try {
            $active=$this->pdo->prepare("SELECT * FROM catalog_jobs WHERE catalog_id=? AND status IN ('pending','running') ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $active->execute([$catalogId]);$job=$active->fetch();
            if(!$job){$this->pdo->prepare('DELETE FROM catalog_jobs WHERE catalog_id=?')->execute([$catalogId]);$statement=$this->pdo->prepare("INSERT INTO catalog_jobs(catalog_id) VALUES(?) RETURNING *");$statement->execute([$catalogId]);$job=$statement->fetch();}
            $this->pdo->commit();return $job;
        } catch (\Throwable $exception) { if($this->pdo->inTransaction())$this->pdo->rollBack();throw $exception; }
    }

    public function latest(int $catalogId): ?array
    {
        $statement=$this->pdo->prepare('SELECT * FROM catalog_jobs WHERE catalog_id=? ORDER BY id DESC LIMIT 1');$statement->execute([$catalogId]);return $statement->fetch()?:null;
    }

    public function claim(): ?array
    {
        $statement=$this->pdo->query("UPDATE catalog_jobs SET status='running',phase='preparing',started_at=NOW(),error='' WHERE id=(SELECT id FROM catalog_jobs WHERE status='pending' ORDER BY id FOR UPDATE SKIP LOCKED LIMIT 1) RETURNING *");
        return $statement->fetch()?:null;
    }

    public function recoverInterrupted(): void
    {
        $this->pdo->exec("UPDATE catalog_jobs SET status='pending',phase='queued',started_at=NULL WHERE status='running'");
    }

    public function progress(int $id,int $current,int $total,string $phase): void
    {
        $statement=$this->pdo->prepare('UPDATE catalog_jobs SET progress_current=?,progress_total=?,phase=? WHERE id=?');$statement->execute([$current,$total,$phase,$id]);
    }

    public function complete(int $id,string $artifact): void
    {
        $statement=$this->pdo->prepare("UPDATE catalog_jobs SET status='completed',phase='completed',artifact_path=?,finished_at=NOW() WHERE id=?");$statement->execute([$artifact,$id]);
    }

    public function fail(int $id,\Throwable $exception): void
    {
        $statement=$this->pdo->prepare("UPDATE catalog_jobs SET status='failed',phase='failed',error=?,finished_at=NOW() WHERE id=?");$statement->execute([mb_substr($exception->getMessage(),0,2000),$id]);
    }
}
