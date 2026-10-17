<?php

declare(strict_types=1);

/**
 * Aşama 3 — Vitrin SEO testleri (SitemapController + StoreProductService + SettingsService).
 *
 * KENDİ test veritabanında (xml_ecommerce_test3_seo) sıfırdan migration +
 * tohumlama ile çalışır. HTTP katmanı olmadan controller seviyesinde doğrular:
 *  - SitemapController::xml(new Request('GET','/sitemap.xml')) → Response::content()
 *    (urlset, ürün/kategori yolları, priority'ler, hide modunda bayat ürün dışlanması)
 *  - SitemapController::robots(...) → Disallow /admin + Sitemap satırı
 *  - StoreProductService::productForStore slug çözümlemesi + bayatlık davranışı
 *  - SettingsService::legal() yer tutucu interpolasyonu ({{STORE_NAME}} → store.name)
 *
 * Not: SitemapController::xml Request::capture() gerektirmez; Request
 * __construct(string $method, string $path) ile üretilir. Controller 'new
 * Response' döndürür; body'ye content() ile erişilir.
 * Tam yol: tests/StoreSeoTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Database\MigrationRunner;
use App\Controllers\Store\SitemapController;
use App\Services\Store\SettingsService;
use App\Services\Store\StoreProductService;

return static function (TestRunner $t): void {
    $host       = (string) Config::get('database.host', '127.0.0.1');
    $port       = (int) Config::get('database.port', 3306);
    $charset    = (string) Config::get('database.charset', 'utf8mb4');
    $username   = (string) Config::get('database.username', 'root');
    $password   = (string) Config::get('database.password', '');
    $originalDb = (string) (env('DB_DATABASE') ?: 'xml_ecommerce'); // env tabanlı: önceki test dosyalarının Config::set sızıntısından bağışık
    $testDb     = $originalDb . '_test3_seo';

    $registerSkips = static function (string $reason) use ($t): void {
        $t->addTest('StoreSeo: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
    };

    $useDb = static function () use ($testDb): void {
        Config::set('database.database', $testDb);
        Database::disconnect();
    };

    $scalar = static function (string $sql, array $params = []) use ($useDb): mixed {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    };

    /** @var array<string,int>|null $state */
    $state = null;

    try {
        try {
            $probe = Database::connect($host, $port, null, $charset, $username, $password);
        } catch (\Throwable) {
            $probe = null;
        }
        if ($probe === null) {
            $registerSkips('MySQL sunucusuna ulaşılamadı');
            return;
        }

        App::boot(BASE_PATH);
        restore_error_handler();
        restore_exception_handler();

        $useDb();

        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        $probe->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $migrationPdo = Database::connect($host, $port, $testDb, $charset, $username, $password);
        $applied = (new MigrationRunner($migrationPdo))->runPending();
        if (count($applied) !== 31) {
            throw new RuntimeException('Beklenmedik migration sayısı: ' . count($applied));
        }

        $useDb();
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('INSERT INTO suppliers (name, code, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
        $stmt->execute(['SEO Test Tedarikçisi', 'SUP-SEO-TEST']);

        $stmt = $pdo->prepare(
            'INSERT INTO categories (parent_id, name, slug, is_active, sort_order, created_at, updated_at)
             VALUES (NULL, ?, ?, 1, 0, NOW(), NOW())'
        );
        $stmt->execute(['SEO Ana Kategori', 'seo-ana']);
        $catMainId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO categories (parent_id, name, slug, is_active, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, 1, 0, NOW(), NOW())'
        );
        $stmt->execute([$catMainId, 'SEO Alt Kategori', 'seo-alt']);
        $catSubId = (int) $pdo->lastInsertId();

        $insertProduct = static function (array $row) use ($pdo, $catMainId): int {
            $stmt = $pdo->prepare(
                'INSERT INTO products
                    (category_id, sku, slug, name, description, currency, sale_price, tax_rate, stock, stock_status, status, source_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, "TRY", ?, 20.00, ?, ?, ?, "manual", NOW(), NOW())'
            );
            $stmt->execute([
                $row['category_id'] ?? $catMainId,
                $row['sku'],
                $row['slug'],
                $row['name'],
                $row['description'] ?? 'SEO test ürünü açıklaması.',
                $row['sale_price'],
                $row['stock'],
                $row['stock'] > 0 ? 'in_stock' : 'out_of_stock',
                $row['status'] ?? 'active',
            ]);
            return (int) $pdo->lastInsertId();
        };

        $insertPsd = static function (int $productId, string $lastSeenAt) use ($pdo): void {
            $stmt = $pdo->prepare(
                'INSERT INTO product_supplier_data
                    (product_id, supplier_id, supplier_sku, supplier_price, supplier_currency, stock, last_seen_at, created_at, updated_at)
                 VALUES (?, 1, ?, 30.0000, "TRY", 50, ?, NOW(), NOW())'
            );
            $stmt->execute([$productId, 'PSD-' . $productId, $lastSeenAt]);
        };

        $p1 = $insertProduct(['sku' => 'SEO-P1', 'slug' => 'seo-urun-bir', 'name' => 'SEO Test Ürünü Bir', 'stock' => 5, 'sale_price' => 100.00]);
        $p2 = $insertProduct(['sku' => 'SEO-P2', 'slug' => 'seo-urun-bayat', 'name' => 'SEO Bayat Ürün', 'stock' => 3, 'sale_price' => 45.00, 'category_id' => $catSubId]);
        $p3 = $insertProduct(['sku' => 'SEO-P3', 'slug' => 'seo-urun-taslak', 'name' => 'SEO Taslak Ürün', 'status' => 'draft', 'stock' => 2, 'sale_price' => 25.00]);

        $insertPsd($p1, date('Y-m-d H:i:s'));
        $insertPsd($p2, date('Y-m-d H:i:s', time() - 72 * 3600)); // bayat (eşik 48h)

        $state = ['catMainId' => $catMainId, 'catSubId' => $catSubId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3];
    } catch (\Throwable $e) {
        try {
            if (isset($probe) && $probe instanceof PDO) {
                $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
            }
        } catch (\Throwable) {
        }
        Config::set('database.database', $originalDb);
        Database::disconnect();
        $registerSkips('Kurulum hatası: ' . $e->getMessage());
        return;
    }

    $p1 = (int) $state['p1'];
    $p2 = (int) $state['p2'];

    $service = static function () use ($useDb): StoreProductService {
        $useDb();
        return new StoreProductService();
    };

    $settings = static function () use ($useDb): SettingsService {
        $useDb();
        return new SettingsService();
    };

    $sitemapXml = static function () use ($service): Response {
        return (new SitemapController($service()))->xml(new Request('GET', '/sitemap.xml'));
    };

    /* ---------------- testler (kayıt sırası = çalışma sırası) ---------------- */

    $t->addTest('StoreSeo: sitemap.xml — urlset, ürün/kategori yolları, priority ve lastmod, taslak yok', static function () use ($sitemapXml, $settings): void {
        $settings()->set('store.stale_stock_action', 'disable');
        $response = $sitemapXml();

        Assert::same(200, $response->status());
        $xml = $response->content();
        Assert::contains('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        Assert::contains('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);

        // Ana sayfa 1.0 + statik sayfalar 0.5 + kategoriler 0.8 + ürünler 0.9
        Assert::contains('<priority>1.0</priority>', $xml);
        Assert::contains('<priority>0.5</priority>', $xml);
        Assert::contains('<priority>0.8</priority>', $xml);
        Assert::contains('<priority>0.9</priority>', $xml);

        // Kategori yolları (ana + alt)
        Assert::contains('/kategori/seo-ana', $xml);
        Assert::contains('/kategori/seo-alt', $xml);

        // Ürün yolları: aktif ürün var, taslak yok; bayat (disable) sitemap'te KALIR
        Assert::contains('/urun/seo-urun-bir', $xml);
        Assert::contains('/urun/seo-urun-bayat', $xml);
        Assert::false(str_contains($xml, 'seo-urun-taslak'), 'Taslak ürün sitemap\'te OLMAMALI');

        // lastmod W3C tarih biçiminde (YYYY-MM-DD)
        Assert::true((bool) preg_match('/<lastmod>\d{4}-\d{2}-\d{2}<\/lastmod>/', $xml), 'Ürün satırında lastmod tarihi olmalı');

        // Content-Type
        Assert::same('application/xml; charset=utf-8', (string) ($response->headers()['Content-Type'] ?? ''));
    });

    $t->addTest('StoreSeo: sitemap hide modunda bayat ürünü dışlar (service filtresi)', static function () use ($sitemapXml, $settings): void {
        try {
            $settings()->set('store.stale_stock_action', 'hide');
            $xml = $sitemapXml()->content();
            Assert::false(str_contains($xml, '/urun/seo-urun-bayat'), 'hide modunda bayat ürün sitemap\'te OLMAMALI');
            Assert::true(str_contains($xml, '/urun/seo-urun-bir'), 'Bayat olmayan ürün sitemap\'te kalmalı');
        } finally {
            $settings()->set('store.stale_stock_action', SettingsService::DEFAULTS['store.stale_stock_action']);
        }
    });

    $t->addTest('StoreSeo: robots.txt — User-agent, Disallow /admin ve Sitemap satırı', static function () use ($settings): void {
        $settings()->set('store.stale_stock_action', 'disable');
        $response = (new SitemapController())->robots(new Request('GET', '/robots.txt'));

        Assert::same(200, $response->status());
        $content = $response->content();
        Assert::contains('User-agent: *', $content);
        Assert::contains('Allow: /', $content);
        Assert::contains('Disallow: /admin', $content);
        Assert::contains('Disallow: /sepet', $content);
        Assert::contains('Disallow: /odeme', $content);
        Assert::contains('Disallow: /hesabim', $content);
        Assert::contains('Sitemap: http://localhost/sitemap.xml', $content, 'Sitemap mutlak URL içermeli');
        Assert::same('text/plain; charset=utf-8', (string) ($response->headers()['Content-Type'] ?? ''));
    });

    $t->addTest('StoreSeo: productForStore slug ile doğru ürünü döndürür; bayat/taslak/bilinmeyen davranışı', static function () use ($service, $settings, $p1, $p2): void {
        $svc = $service();

        $detail = $svc->productForStore('seo-urun-bir');
        Assert::true($detail !== null, 'Aktif ürün detayı gelmeli');
        Assert::same($p1, (int) $detail['id']);
        Assert::same('seo-urun-bir', (string) $detail['slug']);
        Assert::same('SEO Test Ürünü Bir', (string) $detail['name']);
        Assert::same(false, $detail['is_stale']);
        Assert::same(true, $detail['purchasable']);

        // Yanlış slug'lar NULL döner
        Assert::same(null, $svc->productForStore('olmayan-slug'), 'Bilinmeyen slug → NULL');
        Assert::same(null, $svc->productForStore('seo-urun-taslak'), 'Taslak slug → NULL');

        // Bayat ürün: disable → is_stale + satın alınamaz; hide → tamamen NULL
        $settings()->set('store.stale_stock_action', 'disable');
        $staleDetail = $service()->productForStore('seo-urun-bayat');
        Assert::true($staleDetail !== null, 'disable modunda bayat detayı gelmeli');
        Assert::same($p2, (int) $staleDetail['id']);
        Assert::same(true, $staleDetail['is_stale']);
        Assert::same(false, $staleDetail['purchasable']);

        try {
            $settings()->set('store.stale_stock_action', 'hide');
            Assert::same(null, $service()->productForStore('seo-urun-bayat'), 'hide modunda bayat detayı NULL olmalı');
        } finally {
            $settings()->set('store.stale_stock_action', SettingsService::DEFAULTS['store.stale_stock_action']);
        }
    });

    $t->addTest('StoreSeo: SettingsService::legal() yer tutucuları işletme bilgileriyle değiştirir', static function () use ($settings): void {
        $svc = $settings();
        // Test DB'de settings boş → config/legal_texts.php TASLAĞI döner
        $privacy = $svc->legal('legal.privacy');
        Assert::contains('KVKK Aydınlatma Metni', $privacy, 'Config TASLAĞI fallback olarak gelmeli');

        // Varsayılan değerler interpolasyon edilmiş olmalı
        Assert::contains(SettingsService::DEFAULTS['store.name'], $privacy);
        Assert::contains(SettingsService::DEFAULTS['store.contact_email'], $privacy);
        Assert::false(str_contains($privacy, '{{STORE_NAME}}'), '{{STORE_NAME}} kalıntısı OLMAMALI');
        Assert::false(str_contains($privacy, '{{EMAIL}}'), '{{EMAIL}} kalıntısı OLMAMALI');

        // store.name ayarı değişince metin de değişmeli
        $svc->set('store.name', 'Test Mağaza A.Ş.');
        $updated = $settings()->legal('legal.privacy');
        Assert::contains('Test Mağaza A.Ş.', $updated, 'store.name ayarı metne yansımalı');
        Assert::false(str_contains($updated, SettingsService::DEFAULTS['store.name']), 'Eski mağaza adı metinde KALMAMALI');

        // Temizlik: ayarı kaldır (varsayılana dönüş)
        $svc->set('store.name', SettingsService::DEFAULTS['store.name']);
        Assert::same(SettingsService::DEFAULTS['store.name'], $settings()->storeName());
    });

    $t->addTest('StoreSeo: test DB temizlenir ve config geri yüklenir', static function () use ($probe, $testDb, $originalDb, $settings): void {
        $settings()->set('store.stale_stock_action', SettingsService::DEFAULTS['store.stale_stock_action']);
        $settings()->set('store.name', SettingsService::DEFAULTS['store.name']);
        Session::remove('customer_id');
        Session::remove('cart_token');
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        Config::set('database.database', $originalDb);
        Database::disconnect();
        Assert::same($originalDb, Config::get('database.database'), 'Orijinal DB adı geri yüklenmeli');
        Assert::true(true);
    });
};
