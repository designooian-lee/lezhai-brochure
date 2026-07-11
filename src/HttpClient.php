<?php
declare(strict_types=1);

namespace Lezhai;

use RuntimeException;

class HttpClient
{
    private const SOURCE_HOSTS = ['book.yunzhan365.com', 'book.goootu.com', 'flbook.com.cn'];

    public function get(string $url, bool $resource = false): string
    {
        return $this->request($url, 'GET', $resource)['body'];
    }

    public function post(string $url, bool $resource = false): string
    {
        return $this->request($url, 'POST', $resource)['body'];
    }

    public function download(string $url, string $target): void
    {
        $this->validateUrl($url, true);
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new RuntimeException('无法创建下载临时文件。');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'LezhaiBrochure/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FAILONERROR => false,
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($handle);
        if ($ok === false || $status < 200 || $status >= 300 || filesize($target) === 0) {
            @unlink($target);
            throw new RuntimeException("下载失败（HTTP {$status}）{$error}");
        }
        if (!preg_match('~^(image/|application/pdf|application/octet-stream)~i', $type)) {
            @unlink($target);
            throw new RuntimeException('来源返回的不是图片或 PDF。');
        }
    }

    private function request(string $url, string $method, bool $resource, int $redirects = 0): array
    {
        $this->validateUrl($url, $resource);
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_USERAGENT => 'Mozilla/5.0 LezhaiBrochure/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = '';
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('连接图册来源失败：' . $error);
        }
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        if ($status >= 300 && $status < 400 && preg_match('~^Location:\s*(.+)$~mi', $headers, $m)) {
            if ($redirects >= 3) {
                throw new RuntimeException('图册链接跳转次数过多。');
            }
            $location = trim($m[1]);
            if (!preg_match('~^https?://~i', $location)) {
                $parts = parse_url($url);
                $location = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . '/' . ltrim($location, '/');
            }
            return $this->request($location, 'GET', $resource, $redirects + 1);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("图册来源返回 HTTP {$status}。");
        }
        return ['body' => $body, 'headers' => $headers, 'status' => $status];
    }

    private function validateUrl(string $url, bool $resource): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('只允许有效的 HTTP(S) 图册链接。');
        }
        $allowed = in_array($host, self::SOURCE_HOSTS, true);
        if ($resource) {
            $allowed = $allowed
                || preg_match('~^img\d*\.flbook\.com\.cn$~', $host)
                || $host === 'img.flbook.com.cn';
        }
        if (!$allowed) {
            throw new RuntimeException('暂不支持此图册来源域名。');
        }
        foreach (gethostbynamel($host) ?: [] as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('拒绝访问内网或保留地址。');
            }
        }
    }
}
