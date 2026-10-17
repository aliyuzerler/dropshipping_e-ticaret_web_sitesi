<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Güvenli oturum yönetimi.
 *  - httponly + samesite=Lax + strict mode
 *  - secure çerezi HTTPS varsa otomatik
 *  - admin girişinde regenerate(), 45 dk inaktivite zaman aşımı
 *
 * Tam yol: app/Core/Session.php
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $name        = (string) Config::get('session.name', 'xmlshop_session');
        $lifetime    = (int) Config::get('session.lifetime', 2700);
        $secureOpt   = (string) Config::get('session.secure', 'auto');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $secure = $secureOpt === 'true' ? true : ($secureOpt === 'false' ? false : $https);

        session_name($name !== '' ? $name : 'xmlshop_session');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) max($lifetime, 2700));

        session_start();
        self::$started = true;

        // Inaktivite zaman aşımı
        $lastActivity = $_SESSION['_last_activity'] ?? null;
        if (is_int($lastActivity) && (time() - $lastActivity) > $lifetime) {
            self::destroy();
            self::start();
        }
        $_SESSION['_last_activity'] = time();
    }

    public static function isStarted(): bool
    {
        return self::$started || session_status() === PHP_SESSION_ACTIVE;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
        }
        self::$started = false;
    }

    /* ----- Flash mesajları ----- */

    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }
}
