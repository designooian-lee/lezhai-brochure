<?php
declare(strict_types=1);

namespace Lezhai;

final class Config
{
    private static array $values = [];

    public static function load(string $file): void
    {
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $env = getenv($key);
        return $env !== false ? $env : (self::$values[$key] ?? $default);
    }
}

