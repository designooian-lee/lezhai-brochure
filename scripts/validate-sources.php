<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$parser = new Lezhai\CatalogParser();
$urls = array_slice($argv, 1) ?: [
    'https://book.yunzhan365.com/wxfu/egep/mobile/index.html',
    'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=51007',
    'https://flbook.com.cn/c/DUiKDGRoCO',
];
foreach ($urls as $url) {
    try {
        $result = $parser->parse($url);
        echo "OK {$result['source_type']} pages=" . count($result['pages']) . " title={$result['title']}\n";
    } catch (Throwable $e) {
        echo "ERROR {$url}\n{$e->getMessage()}\n";
    }
}
