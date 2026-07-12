<?php
declare(strict_types=1);

namespace Lezhai;

use RuntimeException;

final class CatalogParser
{
    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    public function parse(string $input): array
    {
        $url = trim($input);
        if (preg_match('~index\.htmll$~i', $url)) {
            $fixed = substr($url, 0, -1);
            throw new RuntimeException('链接末尾疑似多了一个 l。建议修正为：' . $fixed);
        }
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        return match ($host) {
            'book.yunzhan365.com' => $this->parseYunzhan($url),
            'book.goootu.com' => $this->parseGoootu($url),
            'flbook.com.cn' => $this->parseFlbook($url),
            default => throw new RuntimeException('暂不支持此图册来源。'),
        };
    }

    private function parseYunzhan(string $url): array
    {
        if (!preg_match('~^https://book\.yunzhan365\.com/([^/]+)/([^/]+)/mobile/index\.html$~i', $url, $m)) {
            throw new RuntimeException('云展网链接格式不正确。');
        }
        $url = "https://book.yunzhan365.com/{$m[1]}/{$m[2]}/mobile/index.html";
        $html = $this->http->get($url);
        $root = "https://book.yunzhan365.com/{$m[1]}/{$m[2]}";
        $pages = $this->browserManifest($url, $root);
        return [
            'source_type' => 'yunzhan365',
            'source_url' => $url,
            'title' => $this->meta($html, 'og:title') ?: $this->title($html),
            'description' => $this->meta($html, 'og:description'),
            'cover_url' => $root . '/files/shot.jpg',
            'pages' => $pages,
            'pdf_url' => null,
        ];
    }

    private function parseGoootu(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $id = (int) ($query['id'] ?? 0);
        if ($id < 1) {
            throw new RuntimeException('goootu 链接缺少有效图册编号。');
        }
        $url = 'http://book.goootu.com/User/Magazine/MagazineView.aspx?id=' . $id;
        $html = $this->http->get($url);
        $data = json_decode($this->http->post('http://book.goootu.com/Ajax/Magazine/MagazineView.ashx?opt=query&id=' . $id), true);
        if (($data['result'] ?? '') !== 'ok' || empty($data['data']['uuid']) || empty($data['data']['total_pages'])) {
            throw new RuntimeException('goootu 页面数据解析失败。');
        }
        $info = $data['data'];
        $base = 'http://book.goootu.com/UpLoads/Magazine/InsidePages/' . $info['uuid'];
        $pages = [];
        for ($page = 1; $page <= (int) $info['total_pages']; $page++) {
            $pages[] = $base . '/1500/' . $page . '.jpg';
        }
        return [
            'source_type' => 'goootu',
            'source_url' => $url,
            'title' => (string) ($info['title'] ?? $this->title($html)),
            'description' => '',
            'cover_url' => $pages[0] ?? ($base . '/1.jpg'),
            'pages' => $pages,
            'pdf_url' => null,
        ];
    }

    private function parseFlbook(string $url): array
    {
        if (!preg_match('~^https://flbook\.com\.cn/c/([A-Za-z0-9]+)~', $url, $m)) {
            throw new RuntimeException('FLBOOK 链接格式不正确。');
        }
        $url = 'https://flbook.com.cn/c/' . $m[1];
        $html = $this->http->get($url);
        if (!preg_match('~"(?:includehtml|pagehtml)":"([^"]+\.html)"~', $html, $pathMatch)) {
            throw new RuntimeException('FLBOOK 页面清单地址解析失败。');
        }
        $path = urldecode(str_replace('\\/', '/', $pathMatch[1]));
        $path = preg_replace('~^\.\./~', '/', $path);
        $pageHtml = $this->http->get('https://flbook.com.cn/' . ltrim($path, '/'), true);
        $pageHtml = str_replace('\\/', '/', $pageHtml);
        preg_match_all('~//(img\d*\.flbook\.com\.cn/[^"\'\s]+?\.(?:jpg|jpeg|png|webp)[^"\'\s]*)~i', $pageHtml, $matches);
        $pages = [];
        foreach ($matches[1] ?? [] as $page) {
            $page = 'https://' . str_replace('\\/', '/', $page);
            $page = html_entity_decode($page, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!in_array($page, $pages, true)) {
                $pages[] = $page;
            }
        }
        if ($pages === []) {
            throw new RuntimeException('FLBOOK 没有解析到页面图片。');
        }
        $pdf = null;
        if (preg_match('~"(?:pdfurl|downpdfurl)":"([^"]+\.pdf[^"]*)"~i', $html, $pdfMatch) && $pdfMatch[1] !== '') {
            $pdf = urldecode(str_replace('\\/', '/', $pdfMatch[1]));
            if (str_starts_with($pdf, '//')) $pdf = 'https:' . $pdf;
            elseif (str_starts_with($pdf, '/')) $pdf = 'https://flbook.com.cn' . $pdf;
        }
        return [
            'source_type' => 'flbook',
            'source_url' => $url,
            'title' => $this->meta($html, 'og:title') ?: $this->title($html),
            'description' => $this->meta($html, 'og:description'),
            'cover_url' => $this->meta($html, 'og:image') ?: ($pages[0] ?? ''),
            'pages' => $pages,
            'pdf_url' => $pdf,
        ];
    }

    private function browserManifest(string $url, string $root): array
    {
        $node = Config::get('NODE_BINARY', 'node');
        $script = dirname(__DIR__) . '/scripts/yunzhan-manifest.js';
        $command = [$node, $script, $url, Config::get('BROWSER_EXECUTABLE')];
        $pipes = [];
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动云展网页面清单解析器。');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $timedOut = false;
        $exitCode = -1;
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) - $started > 90) {
                proc_terminate($process);
                $timedOut = true;
                break;
            }
            usleep(100000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $exitCode = $status['exitcode'] ?? -1;
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($timedOut) {
            throw new RuntimeException('云展网页面清单解析超过 90 秒，已停止本次解析。');
        }
        if ($code === -1) $code = $exitCode;
        $data = json_decode((string) $stdout, true);
        if ($code !== 0 || !is_array($data) || ($data['pages'] ?? []) === []) {
            throw new RuntimeException('云展网页面清单解析失败：' . trim((string) $stderr));
        }
        return array_values(array_filter($data['pages'], static fn ($page) =>
            str_starts_with($page, $root . '/files/large/') || str_starts_with($page, 'browser-render://')
        ));
    }

    private function meta(string $html, string $name): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        foreach ($dom->getElementsByTagName('meta') as $node) {
            $key = $node->getAttribute('property') ?: ($node->getAttribute('name') ?: $node->getAttribute('itemprop'));
            if (strcasecmp($key, $name) === 0) {
                return $this->cleanText($node->getAttribute('content'));
            }
        }
        return '';
    }

    private function title(string $html): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $titles = $dom->getElementsByTagName('title');
        return $titles->length ? $this->cleanText($titles->item(0)?->textContent ?? '') : '';
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('//u', $text) && preg_match('/[ÃÂç¾ä¹]/u', $text)) {
            $converted = @mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
            if (is_string($converted) && preg_match('//u', $converted)) {
                $text = $converted;
            }
        }
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
