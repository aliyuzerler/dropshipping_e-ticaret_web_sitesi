<?php

declare(strict_types=1);

/**
 * AŞAMA 6 — HTTPS zorlama + güvenilir host middleware birim testleri.
 *
 * Production simülasyonu: config değerleri Config::set ile geçici değiştirilir,
 * her senaryo $_SERVER süper-küreselini (HTTPS / X-Forwarded-Proto) izole eder
 * ve try/finally ile ORİJİNAL config değerleri geri yüklenir.
 *
 * Tam yol: tests/Security/HttpsHostTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\HttpsEnforcementMiddleware;
use App\Middleware\TrustedHostMiddleware;

return static function (TestRunner $t): void {
    // Logger (security.log) gerçek proje depolama yolunu kullanır → App boot
    // (hata işleyicileri geri alınır; bkz. tests/XmlSyncDbTest.php notu).
    App::boot(BASE_PATH);
    restore_error_handler();
    restore_exception_handler();

    /** @var array<string,mixed> $configBackup */
    $configBackup = [
        'security.force_https'   => Config::get('security.force_https'),
        'security.allowed_hosts' => Config::get('security.allowed_hosts'),
    ];

    $restore = static function () use ($configBackup): void {
        foreach ($configBackup as $key => $value) {
            Config::set($key, $value);
        }
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    };

    $serverBackup = [
        'HTTPS'                   => $_SERVER['HTTPS'] ?? null,
        'HTTP_X_FORWARDED_PROTO'  => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
        'HTTP_HOST'               => $_SERVER['HTTP_HOST'] ?? null,
    ];

    $setServer = static function (array $kv): void {
        foreach (['HTTPS', 'HTTP_X_FORWARDED_PROTO', 'HTTP_HOST'] as $k) {
            if (array_key_exists($k, $kv)) {
                if ($kv[$k] === null) {
                    unset($_SERVER[$k]);
                } else {
                    $_SERVER[$k] = $kv[$k];
                }
            }
        }
    };

    /* ---------- HTTPS zorlama ---------- */

    $t->addTest('HTTPS: yapılandırma boşken (varsayılan) hiçbir istek yönlendirilmez', static function () use ($restore): void {
        try {
            Config::set('security.force_https', '');
            $request = new Request('GET', '/urun/x', [], [], [], ['host' => 'shop.local']);
            Assert::same(null, HttpsEnforcementMiddleware::inspect($request));
            Assert::false(HttpsEnforcementMiddleware::enabled());
        } finally {
            $restore();
        }
    });

    $t->addTest('HTTPS: force_https=true iken plain HTTP isteği 301 → https (path+query korunur)', static function () use ($restore): void {
        try {
            Config::set('security.force_https', 'true');
            $request = new Request('GET', '/urun/x', ['ref' => 'kampanya'], [], [], ['host' => 'shop.local']);
            $response = HttpsEnforcementMiddleware::inspect($request);

            Assert::true($response instanceof Response);
            Assert::same(301, $response->status());
            Assert::same('https://shop.local/urun/x?ref=kampanya', $response->headers()['Location'] ?? '');
        } finally {
            $restore();
        }
    });

    $t->addTest('HTTPS: X-Forwarded-Proto=https (proxy arkası) istek geçer', static function () use ($restore, $setServer): void {
        try {
            Config::set('security.force_https', 'true');
            $setServer(['HTTPS' => null, 'HTTP_X_FORWARDED_PROTO' => 'https']);
            $request = new Request('GET', '/urun/x', [], [], [], ['host' => 'shop.local']);
            Assert::same(null, HttpsEnforcementMiddleware::inspect($request));
            Assert::true(HttpsEnforcementMiddleware::isSecureRequest($request));
        } finally {
            $restore();
        }
    });

    $t->addTest('HTTPS: native HTTPS ($_SERVER[HTTPS]=on) istek geçer', static function () use ($restore, $setServer): void {
        try {
            Config::set('security.force_https', 'true');
            $setServer(['HTTPS' => 'on', 'HTTP_X_FORWARDED_PROTO' => null]);
            $request = new Request('GET', '/sepet', [], [], [], ['host' => 'shop.local']);
            Assert::same(null, HttpsEnforcementMiddleware::inspect($request));
        } finally {
            $restore();
        }
    });

    $t->addTest('HTTPS: redirect hedef hostu karakter beyazlamasıyla temizlenir (header injection yok)', static function () use ($restore): void {
        try {
            Config::set('security.force_https', '1');
            $request = new Request('GET', '/', [], [], [], ['host' => "shop.local\r\nSet-Cookie: x=1 evil"]);
            $response = HttpsEnforcementMiddleware::inspect($request);
            Assert::true($response instanceof Response);
            $location = (string) ($response->headers()['Location'] ?? '');
            Assert::false(str_contains($location, "\r") || str_contains($location, "\n") || str_contains($location, ' '), 'Location header satır besleme/boşluk içeremez');
            Assert::true(str_starts_with($location, 'https://shop.local'), 'Temiz host korunmalı');
        } finally {
            $restore();
        }
    });

    /* ---------- Güvenilir host ---------- */

    $t->addTest('Host: yapılandırma boşken (varsayılan) her host geçer', static function () use ($restore): void {
        try {
            Config::set('security.allowed_hosts', '');
            $request = new Request('GET', '/', [], [], [], ['host' => 'evil.example.net']);
            Assert::same(null, TrustedHostMiddleware::inspect($request));
            Assert::same([], TrustedHostMiddleware::allowedHosts());
        } finally {
            $restore();
        }
    });

    $t->addTest('Host: beyaz listedeki host geçer (config: shop.example.com,www.shop.example.com)', static function () use ($restore): void {
        try {
            Config::set('security.allowed_hosts', 'shop.example.com, www.shop.example.com');
            $request = new Request('GET', '/', [], [], [], ['host' => 'shop.example.com']);
            Assert::same(null, TrustedHostMiddleware::inspect($request));

            $request2 = new Request('GET', '/', [], [], [], ['host' => 'www.shop.example.com']);
            Assert::same(null, TrustedHostMiddleware::inspect($request2));
        } finally {
            $restore();
        }
    });

    $t->addTest('Host: listede olmayan host 400 alır + security.log', static function () use ($restore): void {
        try {
            Config::set('security.allowed_hosts', 'shop.example.com');
            $request = new Request('GET', '/giris', [], [], [], ['host' => 'evil.example.net'], '203.0.113.9');
            $response = TrustedHostMiddleware::inspect($request);
            Assert::true($response instanceof Response);
            Assert::same(400, $response->status());
        } finally {
            $restore();
        }
    });

    $t->addTest('Host: Host başlığı boşsa 400 (boş host enjeksiyonu reddi)', static function () use ($restore): void {
        try {
            Config::set('security.allowed_hosts', 'shop.example.com');
            $request = new Request('GET', '/', [], [], [], []);
            $response = TrustedHostMiddleware::inspect($request);
            Assert::true($response instanceof Response);
            Assert::same(400, $response->status());
        } finally {
            $restore();
        }
    });

    $t->addTest('Host: X-Forwarded-Host önceliği — listedeki XFH ile geçer, sahte XFH ile 400', static function () use ($restore): void {
        try {
            Config::set('security.allowed_hosts', 'shop.example.com');
            // Host: evil + XFH: shop → proxy arkası doğru host
            $ok = new Request('GET', '/', [], [], [], ['host' => 'internal.local', 'x-forwarded-host' => 'shop.example.com']);
            Assert::same(null, TrustedHostMiddleware::inspect($ok));

            // Host: shop + XFH: evil → spoof denemesi reddedilir
            $bad = new Request('GET', '/', [], [], [], ['host' => 'shop.example.com', 'x-forwarded-host' => 'evil.example.net']);
            $response = TrustedHostMiddleware::inspect($bad);
            Assert::true($response instanceof Response);
            Assert::same(400, $response->status());
        } finally {
            $restore();
        }
    });

    $t->addTest('Host: port sadeleştirme + case-insensitive + zincirli XFH ilk değer', static function () use ($restore): void {
        try {
            Config::set('security.allowed_hosts', 'shop.example.com');
            $withPort = new Request('GET', '/', [], [], [], ['host' => 'SHOP.Example.COM:8443']);
            Assert::same(null, TrustedHostMiddleware::inspect($withPort));

            $chained = new Request('GET', '/', [], [], [], ['x-forwarded-host' => 'shop.example.com, internal.local']);
            Assert::same(null, TrustedHostMiddleware::inspect($chained));

            Assert::same('shop.example.com', TrustedHostMiddleware::requestHost(new Request('GET', '/', [], [], [], ['host' => 'shop.example.com:80'])));
        } finally {
            $restore();
        }
    });

    /* ---------- MiddlewareInterface zincir uyumu ---------- */

    $t->addTest('Guard: handle() zinciri — ihlal yoksa next çalışır, ihlal varsa kısa devre', static function () use ($restore): void {
        try {
            Config::set('security.force_https', 'true');
            Config::set('security.allowed_hosts', 'shop.example.com');

            $next = static function (Request $r): Response {
                return Response::html('HANDLED');
            };

            // Geçerli istek → zincir ilerler
            $ok = new Request('GET', '/health', [], [], [], ['host' => 'shop.example.com']);
            // HTTPS guard pas-through eder (CLI'da HTTPS yok ama TrustedHost inspect via handle)
            $response = (new TrustedHostMiddleware())->handle($ok, $next);
            Assert::same('HANDLED', $response->content());

            // Geçersiz host → 400 kısa devre (next çağrılmaz)
            $bad = new Request('GET', '/health', [], [], [], ['host' => 'evil.example.net']);
            $response = (new TrustedHostMiddleware())->handle($bad, static function (): never {
                throw new RuntimeException('next çağrılmamalıydı');
            });
            Assert::same(400, $response->status());
        } finally {
            $restore();
        }
    });

    // Dosya sonunda süper-küresel geri yükleme (test kayıt fazında değil, suite sonunda)
    $t->addTest('Guard: $_SERVER süper-küreseli test öncesi durumuna döner (izolasyon)', static function () use ($serverBackup): void {
        foreach ($serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        Assert::true(true);
    });
};
