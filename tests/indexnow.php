<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Lezhai\ArticleService;
use Lezhai\Database;
use Lezhai\IndexNowService;

putenv('INDEXNOW_KEY=test-indexnow-key');

$attempted = false;
$indexNow = new IndexNowService(static function () use (&$attempted): void {
    $attempted = true;
    throw new RuntimeException('Expected IndexNow transport failure');
});
$articles = new ArticleService(Database::connection(), $indexNow);
$id = null;

try {
    $id = $articles->save([
        'title' => 'IndexNow failure tolerance',
        'slug' => 'indexnow-failure-tolerance-' . bin2hex(random_bytes(4)),
        'excerpt' => 'The article must still publish when IndexNow fails.',
        'content' => 'IndexNow failures are deliberately non-fatal.',
        'cover_image_url' => null,
        'status' => 'published',
        'published_at' => date('Y-m-d H:i:s'),
    ], null);

    if (!$attempted || $articles->find($id) === null) {
        throw new RuntimeException('IndexNow failure interrupted article publication.');
    }

    echo "IndexNow failure tolerance passed.\n";
} finally {
    if ($id !== null) {
        $articles->delete($id);
    }
    putenv('INDEXNOW_KEY');
}
