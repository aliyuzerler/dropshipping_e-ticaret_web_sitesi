<?php

declare(strict_types=1);

namespace App\Core;

/**
 * config/*.php dosyalarını yükleyip dot notation ile değer sağlar.
 *
 * Tam yol: app/Core/Config.php
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];
    private static bool $loaded = false;

    public static function load(string $configDir): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        foreach (glob(rtrim($configDir, '/') . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            /** @var mixed $data */
            $data = require $file;
            if (is_array($data)) {
                self::$items[$name] = $data;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    /**
     * Çalışma zamanı config geçersiz kılma (testler: ayrı veritabanına yönlendirme).
     * Üretim akışında kullanılmaz.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $last = array_pop($segments);
        $target = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target[$last] = $value;
    }
}
