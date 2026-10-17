<?php

declare(strict_types=1);

/**
 * Genel (public) rotalar.
 * Tam yol: routes/web.php
 *
 * Kullanım: return function(App\Core\Router $r): void { ... };
 *
 * Aşama 3: ana rota kümesi müşteri mağazasına taşındı (routes/store.php);
 * health-check ve admin rotaları yerinde kalır.
 */
use App\Controllers\HealthController;
use App\Middleware\SecurityHeadersMiddleware;

return static function (\App\Core\Router $r): void {
    // Aşama 3 müşteri mağazası (ana sayfa, katalog, sepet, üyelik, hesap, hukuki sayfalar)
    (require __DIR__ . '/store.php')($r);

    // Sağlık kontrolü (izleme uçları)
    $r->get('/health', [HealthController::class, 'show'], [SecurityHeadersMiddleware::class]);
};
