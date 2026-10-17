<?php

declare(strict_types=1);

/**
 * PSR-4 otomatik yükleyici (fallback).
 *
 * Composer varsa vendor/autoload.php kullanılır; bu dosya yalnız Composer
 * kurulu olmayan ortamlarda (CLI testleri, minimal VPS) devreye girer.
 *
 * Tam yol: app/Core/Autoloader.php
 */
final class Autoloader
{
    private static bool $registered = false;

    public static function register(string $prefix = 'App\\', string $baseDir = null): void
    {
        if (self::$registered) {
            return;
        }
        $baseDir = $baseDir ?? (defined('BASE_PATH') ? BASE_PATH . '/app/' : __DIR__ . '/../../app/');

        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });

        self::$registered = true;
    }
}
