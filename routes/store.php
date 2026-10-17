<?php

declare(strict_types=1);

/**
 * AŞAMA 3 — Müşteri mağazası rotaları (SEO dostu Türkçe URL'ler).
 * Tam yol: routes/store.php
 */
use App\Controllers\Store\AccountController;
use App\Controllers\Store\AddressController;
use App\Controllers\Store\AuthController;
use App\Controllers\Store\CatalogController;
use App\Controllers\Store\CartController;
use App\Controllers\Store\CheckoutController;
use App\Controllers\Store\HomeController;
use App\Controllers\Store\OrderController;
use App\Controllers\Store\PageController;
use App\Controllers\Store\PaymentController;
use App\Controllers\Store\ProductController;
use App\Controllers\Store\ContactController;
use App\Controllers\Store\SitemapController;
use App\Middleware\CustomerAuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\SecurityHeadersMiddleware;

return static function (\App\Core\Router $r): void {
    /* --- SEO / teknik --- */
    $r->get('/sitemap.xml', [SitemapController::class, 'xml'], [SecurityHeadersMiddleware::class]);
    $r->get('/robots.txt', [SitemapController::class, 'robots'], [SecurityHeadersMiddleware::class]);

    /* --- Ana sayfa / katalog --- */
    $r->get('/', [HomeController::class, 'index'], [SecurityHeadersMiddleware::class]);
    $r->get('/kategori/{slug}', [CatalogController::class, 'category'], [SecurityHeadersMiddleware::class]);
    $r->get('/urun/{slug}', [ProductController::class, 'show'], [SecurityHeadersMiddleware::class]);
    $r->get('/arama', [CatalogController::class, 'search'], [SecurityHeadersMiddleware::class]);

    /* --- Sepet (misafir + üye) --- */
    $r->get('/sepet', [CartController::class, 'show'], [SecurityHeadersMiddleware::class]);
    $r->post('/sepet/ekle', [CartController::class, 'add'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->post('/sepet/guncelle', [CartController::class, 'update'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->post('/sepet/kaldir', [CartController::class, 'remove'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->post('/sepet/temizle', [CartController::class, 'clear'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->post('/sepet/kupon', [CartController::class, 'applyCoupon'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->post('/sepet/kupon-kaldir', [CartController::class, 'removeCoupon'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);

    /* --- Üyelik --- */
    $r->get('/kayit', [AuthController::class, 'showRegister'], [SecurityHeadersMiddleware::class]);
    $r->post('/kayit', [AuthController::class, 'register'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->get('/giris', [AuthController::class, 'showLogin'], [SecurityHeadersMiddleware::class]);
    $r->post('/giris', [AuthController::class, 'login'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->post('/cikis', [AuthController::class, 'logout'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->get('/sifremi-unuttum', [AuthController::class, 'showForgot'], [SecurityHeadersMiddleware::class]);
    $r->post('/sifremi-unuttum', [AuthController::class, 'forgot'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);
    $r->get('/sifre-sifirla', [AuthController::class, 'showReset'], [SecurityHeadersMiddleware::class]);
    $r->post('/sifre-sifirla', [AuthController::class, 'reset'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);

    /* --- Checkout (üye) --- */
    $r->get('/odeme', [CheckoutController::class, 'show'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);
    $r->post('/odeme/tamamla', [CheckoutController::class, 'place'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);

    /* --- AŞAMA 4: iyzico Checkout Form ödeme akışı (üye) --- */
    $r->get('/odeme/onay/{number}', [PaymentController::class, 'showPay'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);
    $r->get('/odeme/sonuc/{number}', [PaymentController::class, 'result'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);
    // iyzico callback: CSRF YOK (dış gelen POST; kimlik doğrulama token + sunucu sorgusuyla)
    $r->post('/odeme/callback', [PaymentController::class, 'callback'], [SecurityHeadersMiddleware::class]);
    // iyzico webhook: CSRF YOK (imza doğrulaması + tekrarlı bildirim kontrolü servis içinde)
    $r->post('/odeme/webhook', [WebhookController::class, 'handle'], [SecurityHeadersMiddleware::class]);
    // Mock simülasyon (yalnız IYZICO_MODE=mock)
    $r->post('/odeme/simulasyon', [PaymentController::class, 'mockPay'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);
    // Müşteri iade talebi
    $r->post('/hesabim/siparisler/{number}/iade-talebi', [PaymentController::class, 'requestRefund'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);

    /* --- Hesabım (üye) --- */
    $r->get('/hesabim', [AccountController::class, 'dashboard'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);
    $r->post('/hesabim/profil', [AccountController::class, 'updateProfile'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);
    $r->post('/hesabim/sifre', [AccountController::class, 'updatePassword'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);

    /* --- Adres defteri (üye) --- */
    $r->get('/hesabim/adresler', [AddressController::class, 'index'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);
    $r->get('/hesabim/adresler/yeni', [AddressController::class, 'create'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);
    $r->post('/hesabim/adresler', [AddressController::class, 'store'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);
    $r->get('/hesabim/adresler/{id}/duzenle', [AddressController::class, 'edit'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);
    $r->post('/hesabim/adresler/{id}', [AddressController::class, 'update'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);
    $r->post('/hesabim/adresler/{id}/sil', [AddressController::class, 'destroy'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);
    $r->post('/hesabim/adresler/{id}/varsayilan', [AddressController::class, 'makeDefault'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class, CustomerAuthMiddleware::class]);

    /* --- Siparişler (üye) --- */
    $r->get('/hesabim/siparisler', [OrderController::class, 'index'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);
    $r->get('/hesabim/siparisler/{number}', [OrderController::class, 'show'], [SecurityHeadersMiddleware::class, CustomerAuthMiddleware::class]);

    /* --- İletişim --- */
    $r->get('/iletisim', [ContactController::class, 'show'], [SecurityHeadersMiddleware::class]);
    $r->post('/iletisim', [ContactController::class, 'send'], [SecurityHeadersMiddleware::class, CsrfMiddleware::class]);

    /* --- Hukuki / kurumsal sayfalar (TASLAK metinler) --- */
    $r->get('/gizlilik-ve-kvkk', [PageController::class, 'privacy'], [SecurityHeadersMiddleware::class]);
    $r->get('/mesafeli-satis-sozlesmesi', [PageController::class, 'distanceSales'], [SecurityHeadersMiddleware::class]);
    $r->get('/on-bilgilendirme-formu', [PageController::class, 'preInformation'], [SecurityHeadersMiddleware::class]);
    $r->get('/iade-ve-teslimat', [PageController::class, 'returnsDelivery'], [SecurityHeadersMiddleware::class]);
};
