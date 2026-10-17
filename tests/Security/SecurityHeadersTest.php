<?php

declare(strict_types=1);

/**
 * AŞAMA 6 — Güvenlik başlığı testleri.
 *
 *  1) SecurityHeadersMiddleware çıktısı: CSP, X-Frame-Options, X-Content-Type-Options,
 *     Referrer-Policy, Permissions-Policy başlıkları mevcut ve beklenen değerlerde.
 *  2) Rota kapsamı (yapısal regresyon): web+store+admin TÜM rotalarında
 *     SecurityHeadersMiddleware tanımlı; CsrfMiddleware'siz POST rotaları yalnız
 *     iyzico callback/webhook (dokümante istisna listesi).
 *  3) /admin grubundaki her rotada AdminAuthMiddleware var.
 *
 * Tam yol: tests/Security/SecurityHeadersTest.php
 */
use App\Core\Request;
use App\Core\Response;
use App\Middleware\SecurityHeadersMiddleware;

return static function (TestRunner $t): void {
    /* ---------- 1) Middleware çıktısı ---------- */

    $t->addTest('SecurityHeaders: tüm başlıklar beklenen değerlerle üretilir', static function (): void {
        $request  = new Request('GET', '/');
        $response = (new SecurityHeadersMiddleware())->handle($request, static fn (): Response => Response::html('ok'));

        $headers = $response->headers();
        Assert::same("default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; frame-ancestors 'none'", $headers['Content-Security-Policy'] ?? '');
        Assert::same('DENY', $headers['X-Frame-Options'] ?? '', 'Clickjacking: DENY');
        Assert::same('nosniff', $headers['X-Content-Type-Options'] ?? '', 'MIME sniffing: nosniff');
        Assert::same('strict-origin-when-cross-origin', $headers['Referrer-Policy'] ?? '');
        Assert::true(isset($headers['Permissions-Policy']), 'Permissions-Policy mevcut olmalı');
    });

    $t->addTest('SecurityHeaders: JSON yanıtlarına da uygulanır (next zinciri korunur)', static function (): void {
        $request  = new Request('GET', '/health');
        $response = (new SecurityHeadersMiddleware())->handle($request, static fn (): Response => Response::json(['ok' => true]));

        $headers = $response->headers();
        Assert::true(isset($headers['X-Frame-Options']) && isset($headers['Content-Security-Policy']));
        Assert::same('application/json; charset=utf-8', $headers['Content-Type'] ?? '', 'İçerik tipi ezilmemeli');
    });

    /* ---------- 2) Rota kapsamı (web + store + admin) ---------- */

    $registerAll = static function (): App\Core\Router {
        $router = new App\Core\Router();
        (require BASE_PATH . '/routes/web.php')($router);
        (require BASE_PATH . '/routes/admin.php')($router);
        return $router;
    };

    $t->addTest('SecurityHeaders: TÜM rotalarda SecurityHeadersMiddleware tanımlı', static function () use ($registerAll): void {
        foreach ($registerAll()->routes() as $route) {
            $name = $route['method'] . ' ' . $route['path'];
            Assert::true(
                in_array(SecurityHeadersMiddleware::class, $route['middleware'], true),
                "SecurityHeadersMiddleware eksik: $name"
            );
        }
    });

    $t->addTest('SecurityHeaders: CSRF istisnası yalnız iyzico callback + webhook', static function () use ($registerAll): void {
        $exempt = [];
        foreach ($registerAll()->routes() as $route) {
            if ($route['method'] !== 'POST') {
                continue;
            }
            if (!in_array(App\Middleware\CsrfMiddleware::class, $route['middleware'], true)) {
                $exempt[] = $route['path'];
            }
        }
        sort($exempt);
        Assert::same(['/odeme/callback', '/odeme/webhook'], $exempt, 'CSRF istisna listesi dokümante iki uçla sınırlı olmalı');
    });

    $t->addTest('SecurityHeaders: /admin grubundaki her rotada AdminAuthMiddleware var', static function () use ($registerAll): void {
        foreach ($registerAll()->routes() as $route) {
            $path = (string) $route['path'];
            if (!str_starts_with($path, '/admin')) {
                continue;
            }
            // Bilinçli istisna: giriş/çıkış uçları
            if (in_array($path, ['/admin/login', '/admin/logout'], true)) {
                continue;
            }
            Assert::true(
                in_array(App\Middleware\AdminAuthMiddleware::class, $route['middleware'], true),
                "AdminAuthMiddleware eksik: {$route['method']} $path"
            );
        }
    });

    $t->addTest('SecurityHeaders: /admin POST rotalarında (login hariç) CsrfMiddleware var', static function () use ($registerAll): void {
        foreach ($registerAll()->routes() as $route) {
            $path = (string) $route['path'];
            if ($route['method'] !== 'POST' || !str_starts_with($path, '/admin') || $path === '/admin/login') {
                continue;
            }
            Assert::true(
                in_array(App\Middleware\CsrfMiddleware::class, $route['middleware'], true),
                "CsrfMiddleware eksik: $path"
            );
        }
    });
};
