<?php

declare(strict_types=1);

/**
 * AŞAMA 6 — XSS render testleri.
 *
 *  1) e() temel kaçış davranışı (ENT_QUOTES + UTF-8).
 *  2) address_form view'ı: flash'lenen eski form girdisi (kullanıcı POST verisi)
 *     ve adres kaydı değerleri TEK kez kaçışlı basılır (reflected/stored XSS);
 *     çift kodlama (&amp;amp;lt;) oluşmaz. [AŞAMA 6 düzeltmesinin regresyon testi]
 *  3) JSON-LD üretimi: "</script>" payload'ı script bloğundan kaçamaz
 *     (JSON_HEX_TAG) ve çıktı geçerli JSON-LD olarak çözümlenir. [regresyon]
 *
 * Tam yol: tests/Security/XssRenderingTest.php
 */
use App\Core\App;
use App\Core\Session;
use App\Controllers\Store\ProductController;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductImageRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\SettingRepository;
use App\Services\Store\SettingsService;
use App\Services\Store\StoreProductService;

return static function (TestRunner $t): void {
    // Logger/View gerçek proje yollarını kullanır → App boot (hata işleyicileri geri alınır;
    // bkz. tests/XmlSyncDbTest.php notu).
    App::boot(BASE_PATH);
    restore_error_handler();
    restore_exception_handler();

    $payload = '<script>alert(1)</script>';

    $t->addTest('XSS: e() script payload\'ını kaçışlar (ENT_QUOTES)', static function () use ($payload): void {
        $escaped = e($payload);
        Assert::contains('&lt;script&gt;', $escaped);
        Assert::false(str_contains($escaped, '<script>'), 'Ham <script> kalmamalı');
        // Tek tırnak da kaçışlı (ENT_QUOTES): value='...' bağlamlarında güvenli
        Assert::contains('&#039;', e("'zorna"), 'Tek tırnak ENT_QUOTES ile kaçışlı olmalı');
    });

    $t->addTest('XSS: address_form eski form girdisini kaçışlı basar (reflected)', static function () use ($payload): void {
        Session::flash('old', ['title' => $payload, 'full_name' => 'Ay&"se']);
        $html = View::partial('store/account/address_form', ['address' => null, 'errors' => []]);

        Assert::contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'Payload kaçışlı olmalı');
        Assert::false(str_contains($html, '<script>alert(1)'), 'Ham payload view\'a sızmamalı');
        Assert::contains('Ay&amp;&quot;se', $html, 'Değer TAM BİR kez kaçışlı');
        Assert::false(str_contains($html, '&amp;amp;'), 'Çift kodlama yok (tek escape katmanı)');
    });

    $t->addTest('XSS: address_form kayıt değerlerini kaçışlı basar (stored) + çift kodlama yok', static function (): void {
        // NOT: old() fonksiyonu process-bazlı statik önbellek tuttuğundan bu test, yukarıdaki
        // flash anahtarlarıyla ÇAKIŞMAYAN alanları kullanır (line1/city).
        $malicious = ['id' => 5, 'line1' => 'Sokak <img src=x onerror=alert(2)>', 'city' => 'A & B'];
        $html = View::partial('store/account/address_form', ['address' => $malicious, 'errors' => []]);

        Assert::contains('&lt;img src=x onerror=alert(2)&gt;', $html);
        Assert::false(str_contains($html, '<img src=x'), 'Ham HTML sızmamalı');
        Assert::contains('A &amp; B', $html, 'Ampersand TAM BİR kez kaçışlı (çift kodlama yok)');
        Assert::false(str_contains($html, 'A &amp;amp; B'), '&amp;amp; = çift kodlama — olmamalı');
    });

    $t->addTest('XSS: JSON-LD "</script>" payload\'ı script bloğundan kaçamaz (JSON_HEX_TAG)', static function (): void {
        // Ürün repo zinciri (BaseRepository) PDO bekler: sqlite::memory: ile izole et —
        // test hiçbir DB'ye bağlanmaz (buildJsonLd yalnız verilen diziyi işler).
        $pdo = new PDO('sqlite::memory:');
        $service = new StoreProductService(
            new ProductRepository($pdo),
            new CategoryRepository($pdo),
            new ProductImageRepository($pdo),
            new ProductVariantRepository($pdo),
            new SettingsService(new SettingRepository($pdo))
        );
        $controller = new ProductController($service);
        $method = new ReflectionMethod($controller, 'buildJsonLd');
        $method->setAccessible(true);

        $product = [
            'name'       => 'Zehirli Ürün </script><script>alert(3)</script>',
            'sku'        => 'XSS-1',
            'slug'       => 'zehirli-urun',
            'brand'      => 'M<arka>',
            'stock'      => 3,
            'purchasable' => 1,
            'images'     => [],
        ];

        /** @var string $json */
        $json = $method->invoke($controller, $product, 'https://shop.local/urun/zehirli-urun');

        // Çıktı geçerli JSON olmalı ve veri kaybolmamalı
        $decoded = json_decode($json, true);
        Assert::true(is_array($decoded), 'JSON-LD çıktısı geçerli JSON olmalı');
        Assert::same('Zehirli Ürün </script><script>alert(3)</script>', $decoded['@graph'][0]['name'] ?? null, 'Veri çözümlemede birebir kurtulmalı');

        // Ham </script> dizisi metinde bulunamaz (script bloğu erken kapanamaz)
        Assert::false(str_contains($json, '</script>'), 'Ham </script> JSON-LD metninde bulunamaz olmalı');
        Assert::true(str_contains($json, '\u003C/script\u003E') || str_contains($json, '\u003c/script\u003e'), 'TAG hex kaçışı beklenir');
    });

    $t->addTest('XSS: layout dışı gerçek render zinciri — e() çıktısı yeniden kaçışlanınca çift kodlama görülür (davranış referansı)', static function (): void {
        // Referans test: e() idempotent DEĞİLDİR; view'larda tek escape noktası zorunludur.
        $once  = e('A&B');
        $twice = e($once);
        Assert::same('A&amp;B', $once);
        Assert::same('A&amp;amp;B', $twice);
    });
};
