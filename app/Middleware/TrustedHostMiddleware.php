<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * AŞAMA 6 — Güvenilir host kısıtlaması (opt-in, production).
 *
 * Yapılandırma: config/security.php 'allowed_hosts' ← env APP_ALLOWED_HOSTS
 *  - virgülle ayrılmış host listesi: "shop.example.com,www.shop.example.com"
 *  - boş env → kapalı (local/staging etkilenmez)
 *
 * Savunduğu vektörler: Host header injection (parola sıfırlama bağlantısı
 * zehirlenmesi), cache poisoning, host-tabanlı yönlendirme karışıklığı.
 *
 * Davranış: aktifken Host (veya ters proksi arkasında X-Forwarded-Host)
 * normalize edilir (lowercase, port/son nokta temizliği) ve beyaz listeyle
 * birebir karşılaştırılır; listede değilse 400 BadRequest + security.log.
 *
 * Tam yol: app/Middleware/TrustedHostMiddleware.php
 */
final class TrustedHostMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $violation = self::inspect($request);
        return $violation ?? $next($request);
    }

    /**
     * Front controller erken basamak: ihlal varsa yanıt (400), yoksa null.
     */
    public static function inspect(Request $request): ?Response
    {
        $allowed = self::allowedHosts();
        if ($allowed === []) {
            return null; // kapalı
        }

        $host = self::requestHost($request);
        if ($host === '' || !in_array($host, $allowed, true)) {
            Logger::security('host.untrusted', ['host' => $host !== '' ? $host : '(boş)', 'ip' => $request->ip()]);
            return Response::html('Geçersiz istek (host).', 400);
        }

        return null;
    }

    /**
     * @return list<string> normalize edilmiş izinli hostlar (boş yapılandırma → [])
     */
    public static function allowedHosts(): array
    {
        $raw = trim((string) config('security.allowed_hosts', ''));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $entry) {
            $host = self::normalize((string) $entry);
            if ($host !== '') {
                $out[] = $host;
            }
        }
        return $out;
    }

    /** Host başlığını karşılaştırma biçimine indirger (port, trailing dot, büyük/küçük harf). */
    public static function requestHost(Request $request): string
    {
        $raw = (string) ($request->header('x-forwarded-host') ?? $request->header('host') ?? '');
        // Zincirleme X-Forwarded-Host: "client, proxy" → istemci değeri ilk sıradadır
        $first = trim(explode(',', $raw, 2)[0]);
        return self::normalize($first);
    }

    private static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        // host:port biçiminde gelenlerde port'u soy (IPv6 köşeli parantezli hariç — bu
        // proje host beyaz listesi alan adı bazlıdır; port bilgisi karşılaştırmaya girmez)
        $colon = strrpos($host, ':');
        if ($colon !== false && !str_contains($host, ']')) {
            $host = substr($host, 0, $colon);
        }
        return rtrim($host, '.');
    }
}
