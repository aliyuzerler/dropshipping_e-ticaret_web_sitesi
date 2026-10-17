<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSRF token üretimi/doğrulaması (senkron token modeli, timing-safe).
 *
 * Tam yol: app/Core/Csrf.php
 */
final class Csrf
{
    public static function token(): string
    {
        if (!Session::isStarted()) {
            Session::start();
        }
        $token = Session::get('_csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('_csrf_token', $token);
        }
        return $token;
    }

    public static function rotate(): string
    {
        Session::set('_csrf_token', bin2hex(random_bytes(32)));
        return (string) Session::get('_csrf_token');
    }

    public static function validate(?string $provided): bool
    {
        if ($provided === null || $provided === '') {
            return false;
        }
        $expected = Session::get('_csrf_token');
        return is_string($expected) && $expected !== '' && hash_equals($expected, $provided);
    }

    /** Form içi gizli alan */
    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::token()) . '">';
    }
}
