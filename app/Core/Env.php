<?php

declare(strict_types=1);

namespace App\Core;

/**
 * .env dosyası okuyucu (parse_ini kullanmadan, değer boşlukları güvenli).
 *
 * Tam yol: app/Core/Env.php
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $envFile): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($envFile)) {
            return; // .env zorunlu değil; ortam değişkenleri / varsayılanlar geçerli
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            // Tırnakları soy
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);
        if ($v === null) {
            return $default;
        }
        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v === null ? $default : (int) $v;
    }
}
