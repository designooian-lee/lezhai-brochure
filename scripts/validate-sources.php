<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$parser = new Lezhai\CatalogParser();
$failed = false;
$urls = array_slice($argv, 1) ?: [
    'https://book.yunzhan365.com/wxfu/egep/mobile/index.html',
    'https://book.yunzhan365.com/wxfu/nela/mobile/index.html',
    'https://book.yunzhan365.com/gfrl/keqh/mobile/index.html',
    'https://book.yunzhan365.com/pwww/tzyw/mobile/index.html',
    'https://book.yunzhan365.com/wxfu/gjkr/mobile/index.html',
    'https://book.yunzhan365.com/wxfu/jzkx/mobile/index.html',
    'https://book.yunzhan365.com/glos/pitj/mobile/index.html',
];
foreach ($urls as $url) {
    try {
        $result = $parser->parse($url);
        echo "OK {$result['source_type']} pages=" . count($result['pages']) . " title={$result['title']}\n";
    } catch (Throwable $e) {
        $failed = true;
        echo "ERROR {$url}\n{$e->getMessage()}\n";
    }
}
exit($failed ? 1 : 0);
