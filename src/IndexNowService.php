<?php

declare(strict_types=1);

namespace Lezhai;

use Closure;
use Throwable;

final class IndexNowService
{
    private Closure $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport === null
            ? Closure::fromCallable([$this, 'send'])
            : Closure::fromCallable($transport);
    }

    public function key(): string
    {
        $key = trim(Config::get('INDEXNOW_KEY'));
        return preg_match('/^[A-Za-z0-9-]{8,128}$/', $key) ? $key : '';
    }

    public function notify(array $paths): bool
    {
        $key = $this->key();
        if ($key === '') {
            return false;
        }

        $origin = rtrim(Config::get('PUBLIC_ORIGIN', 'https://lezhai.life'), '/');
        $host = (string) parse_url($origin, PHP_URL_HOST);
        $urls = [];
        foreach (array_unique($paths) as $path) {
            if (is_string($path) && str_starts_with($path, '/')) {
                $urls[] = $origin . $path;
            }
        }
        if ($host === '' || $urls === []) {
            return false;
        }

        try {
            ($this->transport)('https://api.indexnow.org/indexnow', [
                'host' => $host,
                'key' => $key,
                'keyLocation' => $origin . '/indexnow-key.txt',
                'urlList' => $urls,
            ]);
            return true;
        } catch (Throwable $exception) {
            error_log('IndexNow notification failed: ' . $exception->getMessage());
            return false;
        }
    }

    private function send(string $endpoint, array $payload): void
    {
        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 2,
        ]);
        $result = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($error !== '' ? $error : 'HTTP ' . $status);
        }
    }
}
