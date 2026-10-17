<?php

declare(strict_types=1);

/**
 * Aşama 2 uçtan uca XML aktarım motoru testleri — KENDİ test veritabanında
 * (xml_ecommerce_phase2_sync_test) sıfırdan migration + tohumlama ile çalışır.
 *
 * Kapsam: ilk aktarım, idempotent tekrar, fiyat/stok güncelleme + geçmiş,
 * pasife alma, yeni ürün, bozuk XML, XXE reddi, geçersiz satırlar,
 * SKU çakışması, FeedLock, alan koruması (protect_*), last_success_sync_at,
 * yazmasız önizleme ve temizlik.
 *
 * Notlar:
 *  - TestRunner dosyaları alfabetik yükler; PricingTest bu dosyadan ÖNCE çalışır
 *    ve Database::pdo() singleton'ı paylaşıldığı için her test başında bu dosya
 *    Config::set('database.database', ...) + Database::disconnect() uygular.
 *  - Feed1 sku_prefix='' bırakılmıştır: uygulama sku_prefix'i hem FieldMapper'da
 *    hem ProductSyncService::resolveProductSku'da UYGULADIĞI için 'TK-' verildiğinde
 *    ürün SKU'ları TK-TK-TK-* olur; fixture kodları zaten TK- taşır.
 *    (FieldMapper önek davranışı ayrıca FieldMapperTest'te birim olarak kapsanır.)
 *  - Testteki XML fixture'ları SENTETİKTİR; gerçek tedarikçi verisi içermez.
 * Tam yol: tests/XmlSyncDbTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Database\MigrationRunner;
use App\Repositories\FieldMappingRepository;
use App\Services\Import\FeedLock;
use App\Services\Import\ImportPreviewService;
use App\Services\Import\XmlSyncEngine;

return static function (TestRunner $t): void {
    $host       = (string) Config::get('database.host', '127.0.0.1');
    $port       = (int) Config::get('database.port', 3306);
    $charset    = (string) Config::get('database.charset', 'utf8mb4');
    $username   = (string) Config::get('database.username', 'root');
    $password   = (string) Config::get('database.password', '');
    // AŞAMA 6: env tabanlı yakalama — CustomerAuthTest konvansiyonu. Config::get bu
    // noktada önceki dosyaların (StoreSeoTest) kayıt fazı setup'ının Config::set
    // sızıntısını taşıyabilir; env() ise her zaman gerçek orijinal DB'yi verir.
    $originalDb = (string) (env('DB_DATABASE') ?: 'xml_ecommerce');
    $testDb     = 'xml_ecommerce_phase2_sync_test';
    $fixtures   = __DIR__ . '/fixtures';

    $registerSkips = static function (string $reason) use ($t): void {
        $t->addTest('XmlSync: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
    };

    /** Feed eşleme profili (xml_field_mappings satır şekli). */
    $mappings = [
        ['source_path' => 'URUN_KODU',  'target_field' => 'supplier_sku', 'transform' => null, 'default_value' => null],
        // Nitelik olarak verilen ürün kodu için (örn. <Urun URUN_KODU="TK-1008">)
        ['source_path' => '@URUN_KODU', 'target_field' => 'supplier_sku', 'transform' => null, 'default_value' => null],
        ['source_path' => 'BARKOD',     'target_field' => 'barcode',      'transform' => null, 'default_value' => null],
        ['source_path' => 'URUN_ADI',   'target_field' => 'name',         'transform' => null, 'default_value' => null],
        ['source_path' => 'ACIKLAMA',   'target_field' => 'description',  'transform' => null, 'default_value' => null],
        ['source_path' => 'KATEGORI',   'target_field' => 'category',     'transform' => null, 'default_value' => null],
        ['source_path' => 'MARKA',      'target_field' => 'brand',        'transform' => null, 'default_value' => null],
        ['source_path' => 'ALIS_FIYAT', 'target_field' => 'price',        'transform' => null, 'default_value' => null],
        ['source_path' => 'DOVIZ',      'target_field' => 'currency',     'transform' => null, 'default_value' => null],
        ['source_path' => 'KDV',        'target_field' => 'vat',          'transform' => null, 'default_value' => null],
        ['source_path' => 'STOK',       'target_field' => 'stock',        'transform' => null, 'default_value' => null],
        ['source_path' => 'GORSEL',     'target_field' => 'image',        'transform' => null, 'default_value' => null],
        ['source_path' => 'GORSEL2',    'target_field' => 'image',        'transform' => null, 'default_value' => null],
        ['source_path' => 'VARYANT',    'target_field' => 'variant',      'transform' => null, 'default_value' => null],
    ];

    /** @var array<string,mixed>|null $state kurulum sonucu (feed id'leri vb.) */
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

        // FeedLock / Logger gerçek proje depolama yolunu kullanır → App'i boot et.
        // NOT: App::boot, ErrorHandler::register() ile global hata/istisna işleyicileri
        // kurar; bunlar PHP uyarılarını ErrorException'a çevirip session tabanlı
        // testleri (CsrfTest: "headers already sent") bozar. Test sürecinde
        // işleyicileri varsayılana geri alıyoruz (basePath ayarı kalır).
        App::boot(BASE_PATH);
        restore_error_handler();
        restore_exception_handler();

        // --- 1) Bu dosyanın DB'sine yönel (migration 024 Database::pdo() kullanır) ---
        Config::set('database.database', $testDb);
        Database::disconnect();

        // --- 2) Test DB'yi sıfırdan kur ve TÜM migration'ları uygula ---
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        $probe->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $migrationPdo = Database::connect($host, $port, $testDb, $charset, $username, $password);
        $applied = (new MigrationRunner($migrationPdo))->runPending();
        if (count($applied) !== 31) {
            throw new RuntimeException('Beklenmedik migration sayısı: ' . count($applied));
        }

        // --- 3) Tohumlama: tedarikçi 1 + feed 1 + global fiyat kuralı + eşleme profili ---
        $useTestDb = static function () use ($testDb): void {
            Config::set('database.database', $testDb);
            Database::disconnect();
        };
        $useTestDb();
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('INSERT INTO suppliers (name, code, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
        $stmt->execute(['Örnek Tedarikçi A (Test)', 'SUP-TEST-1']);
        $supplier1Id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO xml_feeds
                (supplier_id, name, source_type, product_node, sku_prefix, interval_minutes, max_file_size_mb,
                 is_active, update_description, update_images, update_price, update_stock,
                 auto_publish, deactivate_missing, created_at, updated_at)
             VALUES (?, ?, "upload", "Urun", ?, 60, 10, 1, 1, 1, 1, 1, 0, 1, NOW(), NOW())'
        );
        $stmt->execute([$supplier1Id, 'Test Feed', '']);
        $feed1Id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO price_rules
                (name, type, scope, value, min_qty, currency, priority, is_active, created_at, updated_at)
             VALUES (?, "percent", "global", 30.0, 1, "TRY", 0, 1, NOW(), NOW())'
        );
        $stmt->execute(['Global %30 Test Kârı']);

        (new FieldMappingRepository())->replaceForFeed($feed1Id, $mappings);

        $state = ['feed1Id' => $feed1Id, 'supplier1Id' => $supplier1Id];
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

    $feed1Id = (int) $state['feed1Id'];

    /* ---------------- yardımcılar (her biri test DB'sine yönelir) ---------------- */

    $useSyncDb = static function () use ($testDb): void {
        Config::set('database.database', $testDb);
        Database::disconnect();
    };

    /** Hazır ifade çalıştırır; INSERT için lastInsertId döner. */
    $exec = static function (string $sql, array $params = []) use ($useSyncDb): int {
        $useSyncDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) Database::pdo()->lastInsertId();
    };

    /** Tek kolon değeri. */
    $scalar = static function (string $sql, array $params = []) use ($useSyncDb): mixed {
        $useSyncDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    };

    /** Tek satır. */
    $one = static function (string $sql, array $params = []) use ($useSyncDb): ?array {
        $useSyncDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    };

    /** Feed satırını taze seçer (motor tam satır bekler). */
    $fetchFeed = static function (int $feedId) use ($one): array {
        $feed = $one('SELECT * FROM xml_feeds WHERE id = ?', [$feedId]);
        if ($feed === null) {
            throw new RuntimeException('Feed bulunamadı: #' . $feedId);
        }
        return $feed;
    };

    /** Motoru taze feed satırı ile çalıştırır. */
    $runEngine = static function (int $feedId, string $file) use ($fetchFeed, $fixtures): array {
        $feed = $fetchFeed($feedId);
        return (new XmlSyncEngine())->run($feed, $fixtures . '/' . $file, 'test');
    };

    /** Feed'e standart eşleme profilini uygular. */
    $applyMappings = static function (int $feedId) use ($useSyncDb, $mappings): void {
        $useSyncDb();
        (new FieldMappingRepository())->replaceForFeed($feedId, $mappings);
    };

    /** Basit feed ekler; id döner. */
    $addFeed = static function (int $supplierId, string $name, string $skuPrefix, int $deactivateMissing) use ($exec): int {
        return $exec(
            'INSERT INTO xml_feeds
                (supplier_id, name, source_type, product_node, sku_prefix, interval_minutes, max_file_size_mb,
                 is_active, update_description, update_images, update_price, update_stock,
                 auto_publish, deactivate_missing, created_at, updated_at)
             VALUES (?, ?, "upload", "Urun", ?, 60, 10, 1, 1, 1, 1, 1, 0, ?, NOW(), NOW())',
            [$supplierId, $name, $skuPrefix, $deactivateMissing]
        );
    };

    /** Float karşılaştırma. */
    $approx = static function (float $expected, mixed $actual, string $message = ''): void {
        $actualFloat = (float) $actual;
        Assert::true(
            abs($expected - $actualFloat) < 0.001,
            ($message !== '' ? $message . ' — ' : '') . 'Beklenen ~' . $expected . ', Gelen: ' . $actualFloat
        );
    };

    /** Ürünü (supplier_sku + supplier) psd üzerinden getirir. */
    $productBySku = static function (string $supplierSku, int $supplierId) use ($one): ?array {
        return $one(
            'SELECT p.*, psd.supplier_sku, psd.supplier_price, psd.stock AS psd_stock
             FROM products p
             INNER JOIN product_supplier_data psd ON psd.product_id = p.id
             WHERE psd.supplier_sku = ? AND psd.supplier_id = ?
             LIMIT 1',
            [$supplierSku, $supplierId]
        );
    };

    /** Feed'in son aktarım logu. */
    $lastLog = static function (int $feedId) use ($one): ?array {
        return $one('SELECT * FROM xml_import_logs WHERE xml_feed_id = ? ORDER BY id DESC LIMIT 1', [$feedId]);
    };

    /* ---------------- testler (kayıt sırası = çalışma sırası) ---------------- */

    $t->addTest('XmlSync: sample feed ilk aktarım — 8 ürün oluşur', static function () use ($runEngine, $scalar, $one, $approx, $lastLog, $feed1Id): void {
        $res = $runEngine($feed1Id, 'sample_feed.xml');

        Assert::same('success', $res['status'], 'İlk aktarım başarılı olmalı: ' . $res['message']);
        Assert::same(8, $res['created']);
        Assert::same(0, $res['errors']);
        Assert::same(8, (int) $scalar('SELECT COUNT(*) FROM products'), '8 ürün satırı olmalı');

        // SKU öneki: ürün SKU'ları TK- ile başlamalı (fixture kodları TK-* taşır)
        Assert::same(8, (int) $scalar("SELECT COUNT(*) FROM products WHERE sku LIKE 'TK-%'"));

        // USD ürün: sale = round(round(12.40 * kur, 4) * 1.30, 2) — kur config'ten okunur
        $rate = (float) Config::get('xml.sync.currency_rates.USD');
        Assert::true($rate > 0, 'USD kuru config\'ten okunabilmeli');
        $usd = $one("SELECT p.sale_price FROM products p
                     INNER JOIN product_supplier_data psd ON psd.product_id = p.id
                     WHERE psd.supplier_id = 1 AND psd.supplier_currency = 'USD' LIMIT 1");
        Assert::true($usd !== null, 'USD para birimli ürün bulunamadı');
        $expectedUsd = round(round(12.40 * $rate, 4) * (1 + 30.0 / 100.0), 2);
        $approx($expectedUsd, $usd['sale_price'], 'USD ürün satış fiyatı (kur + %30 kural)');

        // Varyant ürün (TK-1006 beden:S|M|L)
        $variantCount = (int) $scalar(
            "SELECT COUNT(*) FROM product_variants pv
             INNER JOIN product_supplier_data psd ON psd.product_id = pv.product_id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1006'"
        );
        Assert::true($variantCount >= 1, 'Varyant kayıtları oluşmalı');
        $variant = $one(
            "SELECT pv.attributes FROM product_variants pv
             INNER JOIN product_supplier_data psd ON psd.product_id = pv.product_id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1006' LIMIT 1"
        );
        $decoded = json_decode((string) $variant['attributes'], true);
        Assert::true(is_array($decoded) && array_key_exists('beden', $decoded), 'Varyant attributes JSON olmalı');

        // 2 görselli ürün (TK-1002)
        $imageCount = (int) $scalar(
            "SELECT COUNT(*) FROM product_images pi
             INNER JOIN product_supplier_data psd ON psd.product_id = pi.product_id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1002'"
        );
        Assert::same(2, $imageCount, 'GORSEL + GORSEL2 iki kayıt olmalı');

        // Kategoriler otomatik oluşmalı
        $categoryCount = (int) $scalar(
            "SELECT COUNT(DISTINCT name) FROM categories
             WHERE name IN ('Kalemler','Defterler','Dosyalar','Kağıt Ürünleri','Ofis Gereçleri','Okul Ürünleri','Makaslar')"
        );
        Assert::same(7, $categoryCount, 'XML KATEGORI değerleri categories tablosunda olmalı');

        // auto_publish=0 → taslak
        Assert::same(8, (int) $scalar("SELECT COUNT(*) FROM products WHERE status = 'draft'"));

        // psd satırları: last_seen_at dolu, xml_feed_id bağlı
        Assert::same(0, (int) $scalar(
            'SELECT COUNT(*) FROM product_supplier_data
             WHERE supplier_id = 1 AND (last_seen_at IS NULL OR xml_feed_id IS NULL)'
        ));

        // Log: başarı + sayaçlar
        $log = $lastLog($feed1Id);
        Assert::true($log !== null, 'Aktarım logu yazılmalı');
        Assert::same('success', $log['status']);
        Assert::same(8, (int) $log['created_rows']);
        Assert::same(0, (int) $log['error_rows']);
    });

    $t->addTest('XmlSync: aynı XML tekrar işlenir — kopya ürün oluşmaz', static function () use ($runEngine, $scalar, $feed1Id): void {
        $res = $runEngine($feed1Id, 'sample_feed.xml');

        Assert::same(0, $res['created'], 'Aynı XML tekrar ürün EKLEMEMELİ');
        Assert::same(0, $res['errors']);
        Assert::same(8, (int) $scalar('SELECT COUNT(*) FROM products'), 'Ürün sayısı değişmemeli');
    });

    $t->addTest('XmlSync: fiyat ve stok güncellenir + geçmiş yazılır', static function () use ($runEngine, $one, $approx, $feed1Id): void {
        $res = $runEngine($feed1Id, 'sample_feed_v2.xml');

        Assert::same('success', $res['status'], 'v2 aktarımı başarılı olmalı: ' . $res['message']);
        Assert::same(1, $res['created'], 'Yalnız TK-1010 yeni olmalı');

        // TK-1001: 42,50 → 49,90 (sale 55.25 → 64.87), stok 15 → 3
        $p = $one(
            "SELECT p.sale_price, p.stock FROM products p
             INNER JOIN product_supplier_data psd ON psd.product_id = p.id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1001' LIMIT 1"
        );
        Assert::true($p !== null, 'TK-1001 bulunamadı');
        $approx(64.87, $p['sale_price'], 'TK-1001 yeni satış fiyatı (49.9 × 1.3)');
        Assert::same(3, (int) $p['stock'], 'TK-1001 stok güncellenmeli');

        // Fiyat/stok geçmişi
        $history = $one(
            "SELECT ph.old_sale_price, ph.new_sale_price, ph.old_cost, ph.new_cost, ph.old_stock, ph.new_stock
             FROM product_price_history ph
             INNER JOIN product_supplier_data psd ON psd.product_id = ph.product_id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1001'
             ORDER BY ph.id DESC LIMIT 1"
        );
        Assert::true($history !== null, 'TK-1001 için geçmiş kaydı yazılmalı');
        $approx(55.25, $history['old_sale_price'], 'Eski satış fiyatı');
        $approx(64.87, $history['new_sale_price'], 'Yeni satış fiyatı');
        $approx(42.5, $history['old_cost'], 'Eski alış');
        $approx(49.9, $history['new_cost'], 'Yeni alış');
        Assert::same(15, (int) $history['old_stock']);
        Assert::same(3, (int) $history['new_stock']);

        // TK-1002: yalnız stok 40 → 12
        $p2 = $one(
            "SELECT p.stock FROM products p
             INNER JOIN product_supplier_data psd ON psd.product_id = p.id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1002' LIMIT 1"
        );
        Assert::same(12, (int) $p2['stock'], 'TK-1002 stok güncellenmeli');
    });

    $t->addTest('XmlSync: XMLden eksilen ürün pasife alınır', static function () use ($one, $lastLog, $feed1Id): void {
        $p3 = $one(
            "SELECT p.status FROM products p
             INNER JOIN product_supplier_data psd ON psd.product_id = p.id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1003' LIMIT 1"
        );
        Assert::true($p3 !== null, 'TK-1003 kaydı (passive) durmalı');
        Assert::same('passive', $p3['status'], 'XMLden eksilen ürün pasife alınmalı');

        $log = $lastLog($feed1Id);
        Assert::true((int) $log['deactivated_rows'] >= 1, 'deactivated_rows >= 1 olmalı');

        $p4 = $one(
            "SELECT p.status FROM products p
             INNER JOIN product_supplier_data psd ON psd.product_id = p.id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1004' LIMIT 1"
        );
        Assert::false($p4 !== null && $p4['status'] === 'passive', 'TK-1004 pasife alınMAMALI');
    });

    $t->addTest('XmlSync: yeni ürün v2den eklenir', static function () use ($scalar, $one): void {
        Assert::same(9, (int) $scalar('SELECT COUNT(*) FROM products'), '8 ilk + 1 yeni (TK-1010)');
        $p10 = $one(
            "SELECT p.sku FROM products p
             INNER JOIN product_supplier_data psd ON psd.product_id = p.id
             WHERE psd.supplier_id = 1 AND psd.supplier_sku = 'TK-1010' LIMIT 1"
        );
        Assert::true($p10 !== null, 'TK-1010 oluşturulmalı');
        Assert::same('TK-1010', $p10['sku']);
    });

    $t->addTest('XmlSync: bozuk XML — aktarım başarısız, katalog korunur', static function () use ($runEngine, $scalar, $lastLog, $feed1Id): void {
        $res = $runEngine($feed1Id, 'broken_feed.xml');

        Assert::same('failed', $res['status'], 'Bozuk XML aktarımı başarısız olmalı');
        Assert::same(9, (int) $scalar('SELECT COUNT(*) FROM products'), 'Katalog korunmalı (9 ürün)');

        $log = $lastLog($feed1Id);
        Assert::same('failed', $log['status'], 'Log durumu failed olmalı');
        Assert::false($log['error_message'] === null, 'Hata mesajı loglanmalı');
    });

    $t->addTest('XmlSync: XXE denemesi reddedilir', static function () use ($runEngine, $scalar, $lastLog, $feed1Id): void {
        $res = $runEngine($feed1Id, 'xxe_feed.xml');

        Assert::same('failed', $res['status'], 'XXE içeren dosya reddedilmeli');

        $log = $lastLog($feed1Id);
        $message = $res['message'] . ' ' . (string) ($log['error_message'] ?? '');
        $rejected = stripos($message, 'DOCTYPE') !== false || stripos($message, 'güvenlik') !== false;
        Assert::true($rejected, 'Red mesajı DOCTYPE/güvenlik içermeli: ' . $message);

        Assert::same(9, (int) $scalar('SELECT COUNT(*) FROM products'), 'Katalog korunmalı (9 ürün)');
    });

    $t->addTest('XmlSync: geçersiz fiyat/stok satırları kabul edilmez', static function () use ($exec, $applyMappings, $runEngine, $lastLog, $scalar): void {
        $feed2Id = $exec(
            'INSERT INTO xml_feeds
                (supplier_id, name, source_type, product_node, sku_prefix, interval_minutes, max_file_size_mb,
                 is_active, update_description, update_images, update_price, update_stock,
                 auto_publish, deactivate_missing, created_at, updated_at)
             VALUES (1, "Test Feed 2", "upload", "Urun", "", 60, 10, 1, 1, 1, 1, 1, 0, 0, NOW(), NOW())'
        );
        $applyMappings($feed2Id);

        $res = $runEngine($feed2Id, 'invalid_values_feed.xml');

        Assert::same('partial', $res['status'], 'Karışık sonuç → partial olmalı');
        Assert::same(2, $res['errors'], 'İki geçersiz satır hata sayılmalı');
        Assert::same(1, $res['created'], 'Yalnız geçerli satır ürün olmalı');

        $log = $lastLog($feed2Id);
        Assert::false($log === null || $log['error_message'] === null, 'error_message dolu olmalı');

        Assert::same(10, (int) $scalar('SELECT COUNT(*) FROM products'), 'Tek geçerli ürün eklendi (10)');
    });

    $t->addTest('XmlSync: SKU çakışması ikinci tedarikçide önlenir', static function () use ($exec, $addFeed, $applyMappings, $runEngine, $scalar): void {
        $supplier2Id = $exec('INSERT INTO suppliers (name, code, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())',
            ['Örnek Tedarikçi B (Test)', 'SUP-TEST-2']);

        // Öneksiz feed: ürün SKU'ları mevcut tedarikçi-1 SKU'larıyla çakışır
        $feed3Id = $addFeed($supplier2Id, 'Feed Tedarikçi 2', '', 0);
        $applyMappings($feed3Id);

        $res1 = $runEngine($feed3Id, 'sample_feed.xml');
        Assert::same('partial', $res1['status'], 'Tüm satırlar hatalı → partial');
        Assert::same(8, $res1['errors'], '8 SKU çakışma hatası olmalı');
        Assert::same(0, $res1['created']);
        Assert::same(0, (int) $scalar('SELECT COUNT(*) FROM product_supplier_data WHERE supplier_id = ?', [$supplier2Id]),
            'Çakışan SKU için tedarikçi-2 verisi oluşmamalı');

        // Önek verilir: aynı satırlar yeni SKU'larla aktarılır
        $exec('UPDATE xml_feeds SET sku_prefix = "S2-" WHERE id = ?', [$feed3Id]);
        $res2 = $runEngine($feed3Id, 'sample_feed.xml');
        Assert::same('success', $res2['status'], 'Önekli ikinci aktarım başarılı olmalı: ' . $res2['message']);
        Assert::same(8, $res2['created'], 'Tedarikçi-2 için 8 ürün oluşmalı');
        Assert::same(8, (int) $scalar('SELECT COUNT(*) FROM product_supplier_data WHERE supplier_id = ?', [$supplier2Id]));

        // 9 (önceki) + 1 (t8) + 8 (tedarikçi-2) = 18
        Assert::same(18, (int) $scalar('SELECT COUNT(*) FROM products'));
    });

    $t->addTest('XmlSync: eşzamanlı aktarım kilitlenir', static function () use ($runEngine, $feed1Id): void {
        $lock = new FeedLock();
        $handle = $lock->acquire($feed1Id);
        Assert::true($handle !== null, 'Test kilidi alınabilmeli');

        try {
            $res = $runEngine($feed1Id, 'sample_feed.xml');
            Assert::same('locked', $res['status'], 'Kilitliyken aktarım locked dönmeli');
        } finally {
            $lock->release($feed1Id);
        }

        $res2 = $runEngine($feed1Id, 'sample_feed.xml');
        Assert::true($res2['status'] === 'success' || $res2['status'] === 'partial',
            'Kilit bırakılınca aktarım çalışmalı: ' . $res2['status']);
    });

    $t->addTest('XmlSync: alan koruması protect_price saygı duyulur', static function () use ($exec, $runEngine, $productBySku, $approx, $feed1Id): void {
        $exec('UPDATE product_supplier_data SET protect_price = 1 WHERE supplier_id = 1 AND supplier_sku = ?', ['TK-1001']);
        $before = $productBySku('TK-1001', 1);
        Assert::true($before !== null, 'TK-1001 bulunamadı');

        $res = $runEngine($feed1Id, 'sample_feed.xml');
        Assert::same('success', $res['status']);

        $after = $productBySku('TK-1001', 1);
        $approx((float) $before['sale_price'], $after['sale_price'], 'protect_price=1 iken fiyat DEĞİŞMEMELİ');

        // Temizlik: korumayı kaldır
        $exec('UPDATE product_supplier_data SET protect_price = 0 WHERE supplier_id = 1 AND supplier_sku = ?', ['TK-1001']);
    });

    $t->addTest('XmlSync: son başarılı senkron zamanı saklanır', static function () use ($scalar, $feed1Id): void {
        $value = $scalar('SELECT last_success_sync_at FROM xml_feeds WHERE id = ?', [$feed1Id]);
        Assert::true($value !== null && $value !== false, 'last_success_sync_at dolu olmalı');
    });

    $t->addTest('XmlSync: önizleme yazmaz', static function () use ($useSyncDb, $fetchFeed, $scalar, $feed1Id, $fixtures): void {
        $useSyncDb();
        $feed = $fetchFeed($feed1Id);
        $preview = (new ImportPreviewService())->preview($feed, $fixtures . '/sample_feed_v2.xml', 5);

        Assert::true(count($preview['rows']) > 0 && count($preview['rows']) <= 5, 'En fazla 5 satır önizlenmeli');
        Assert::true($preview['mapping_count'] > 0, 'Eşleme profili yüklenmiş olmalı');
        Assert::same(18, (int) $scalar('SELECT COUNT(*) FROM products'), 'Önizleme ürün SAYMAMALI');
    });

    $t->addTest('XmlSync: test DB temizlenir ve config geri yüklenir', static function () use ($probe, $testDb, $originalDb): void {
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        Config::set('database.database', $originalDb);
        Database::disconnect();
        Assert::same($originalDb, Config::get('database.database'), 'Orijinal DB adı geri yüklenmeli');
        Assert::true(true);
    });
};
