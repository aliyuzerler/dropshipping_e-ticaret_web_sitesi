<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Uygulama önyükleyici: BASE_PATH, .env, config, hata işleyicileri, saat dilimi.
 *
 * Tam yol: app/Core/App.php
 */
final class App
{
    private static string $basePath = '';
    private static bool $booted = false;

    public static function boot(string $basePath): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$basePath = rtrim($basePath, '/');

        // 1) Ortam değişkenleri ve config
        Env::load(self::$basePath . '/.env');
        Config::load(self::$basePath . '/config');

        // 2) Saat dilimi
        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

        // 3) Hata/istisna işleyicileri
        ErrorHandler::register();

        // 4) Fallback autoloader (composer yoksa)
        if (!class_exists('App\\Core\\Logger', false)) {
            \Autoloader::register();
        }
    }

    public static function basePath(string $path = ''): string
    {
        return self::$basePath . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }

    public static function storagePath(string $path = ''): string
    {
        return self::basePath('storage' . ($path !== '' ? '/' . ltrim($path, '/') : ''));
    }

    public static function environment(): string
    {
        return (string) Config::get('app.env', 'production');
    }

    public static function isProduction(): bool
    {
        return self::environment() === 'production';
    }
}
