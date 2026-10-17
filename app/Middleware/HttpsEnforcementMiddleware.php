<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

/**
 * AŞAMA 6 — HTTPS zorlama (opt-in, production).
 *
 * Yapılandırma: config/security.php 'force_https' ← env APP_FORCE_HTTPS
 *  - 'true' / '1' / 'on' / 'yes' (case-insensitive) → aktif
 *  - boş / diğer → kapalı (local ve staging etkilenmez)
 *
 * Davranış: aktifken HTTPS olmayan her istek, aynı host + path + query'ye
 * 301 ile yönlendirilir. Şema algısı X-Forwarded-Proto desteklidir (ters
 * proksi arkasında doğru çalışır). Hedef host güvenlik için karakter
 * beyazlamasıyla temizlenir (open-redirect/host-injection yok).
 *
 * Tam yol: app/Middleware/HttpsEnforcementMiddleware.php
 */
final class HttpsEnforcementMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $violation = self::inspect($request);
        return $violation ?? $next($request);
    }

    /**
     * Front controller erken basamak: ihlal varsa yanıt (301), yoksa null.
     */
    public static function inspect(Request $request): ?Response
    {
        if (!self::enabled()) {
            return null;
        }
        if (self::isSecureRequest($request)) {
            return null;
        }

        return Response::redirect('https://' . self::safeHost($request) . self::uriWithQuery($request), 301);
    }

    /** Yapılandırma aktif mi? */
    public static function enabled(): bool
    {
        return in_array(
            strtolower(trim((string) config('security.force_https', ''))),
            ['true', '1', 'on', 'yes'],
            true
        );
    }

    /**
     * İstek HTTPS mi? (Session::start() ile aynı protokol algısı — proxy uyumlu)
     */
    public static function isSecureRequest(Request $request): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** Redirect hedefi için temizlenmiş host (X-Forwarded-Host önceliği request_base_url ile uyumlu). */
    private static function safeHost(Request $request): string
    {
        $host = (string) ($request->header('x-forwarded-host')
            ?? $request->header('host')
            ?? $_SERVER['HTTP_HOST']
            ?? 'localhost');
        return preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host) ?: 'localhost';
    }

    /** Path + korunmuş query string. */
    private static function uriWithQuery(Request $request): string
    {
        $uri = $request->path();
        $query = $request->query();
        if ($query !== []) {
            $qs = http_build_query($query);
            if ($qs !== '') {
                $uri .= '?' . $qs;
            }
        }
        return $uri;
    }
}
