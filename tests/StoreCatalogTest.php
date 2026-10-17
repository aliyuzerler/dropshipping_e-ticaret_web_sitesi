<?php

declare(strict_types=1);

/**
 * Aşama 3 — Mağaza katalog servis testleri (StoreProductService + ProductRepository).
 *
 * KENDİ test veritabanında (xml_ecommerce_test3_catalog) sıfırdan migration +
 * tohumlama ile çalışır. Kapsam: aktif/silinmemiş filtresi, bayat stok
 * (hide/disable) davranışı, kategori ağacı filtresi (descendantIds),
 * arama (q / barkod), price_asc sıralama, sayfalama, staleProductIds ve
 * productForStore bayatlık davranışı.
 *
 * Not: TestRunner dosyaları alfabetik yükler ve Database::pdo() singleton'ı
 * paylaşılır; her test başında Config::set('database.database', ...) +
 * Database::disconnect() ile bu dosyanın test DB'sine yönelinir (dosya kendi
 * durumunu son testte geri yükler).
 * Tam yol: tests/StoreCatalogTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Database\MigrationRunner;
use App\Repositories\ProductRepository;
use App\Services\Store\SettingsService;
use App\Services\Store\StoreProductService;

return static function (TestRunner $t): void {
    $host       = (string) Config::get('database.host', '127.0.0.1');
    $port       = (int) Config::get('database.port', 3306);
    $charset    = (string) Config::get('database.charset', 'utf8mb4');
    $username   = (string) Config::get('database.username', 'root');
    $password   = (string) Config::get('database.password', '');
    $originalDb = (string) (env('DB_DATABASE') ?: 'xml_ecommerce'); // env tabanlı: önceki test dosyalarının Config::set sızıntısından bağışık
    $testDb     = $originalDb . '_test3_catalog';

    $registerSkips = static function (string $reason) use ($t): void {
        $t->addTest('StoreCatalog: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
    };

    /** Her test/yardımcı başında bu dosyanın DB'sine yönel. */
    $useDb = static function () use ($testDb): void {
        Config::set('database.database', $testDb);
        Database::disconnect();
    };

    /** Hazır ifade çalıştırır; INSERT için lastInsertId döner. */
    $exec = static function (string $sql, array $params = []) use ($useDb): int {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) Database::pdo()->lastInsertId();
    };

    /** Tek kolon değeri. */
    $scalar = static function (string $sql, array $params = []) use ($useDb): mixed {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    };

    /** Float karşılaştırma. */
    $approx = static function (float $expected, mixed $actual, string $message = ''): void {
        $actualFloat = (float) $actual;
        Assert::true(
            abs($expected - $actualFloat) < 0.001,
            ($message !== '' ? $message . ' — ' : '') . 'Beklenen ~' . $expected . ', Gelen: ' . $actualFloat
        );
    };

    /** @var array<string,int>|null $state kurulum sonucu (id haritası) */
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

        // Logger gerçek depolama yolunu kullanır → App'i boot et; ardından global
        // hata işleyicilerini varsayılana geri al (diğer testleri bozmamak için).
        App::boot(BASE_PATH);
        restore_error_handler();
        restore_exception_handler();

        // --- 1) Bu dosyanın DB'sine yönel (024+ migration'lar Database::pdo() kullanır) ---
        $useDb();

        // --- 2) Test DB'yi sıfırdan kur ve TÜM migration'ları uygula ---
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        $probe->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $migrationPdo = Database::connect($host, $port, $testDb, $charset, $username, $password);
        $applied = (new MigrationRunner($migrationPdo))->runPending();
        if (count($applied) !== 31) {
            throw new RuntimeException('Beklenmedik migration sayısı: ' . count($applied));
        }

        // --- 3) Tohumlama: tedarikçi + feed + kategori ağacı + U1..U6 ---
        $useDb();
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('INSERT INTO suppliers (name, code, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
        $stmt->execute(['Katalog Test Tedarikçisi', 'SUP-CAT-TEST']);
        $supplierId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO xml_feeds
                (supplier_id, name, source_type, product_node, sku_prefix, interval_minutes, max_file_size_mb,
                 is_active, auto_publish, deactivate_missing, created_at, updated_at)
             VALUES (?, ?, "upload", "Urun", "", 60, 10, 1, 0, 0, NOW(), NOW())'
        );
        $stmt->execute([$supplierId, 'Katalog Test Feed']);
        $feedId = (int) $pdo->lastInsertId();

        $insertCategory = static function (?int $parentId, string $name, string $slug) use ($pdo): int {
            $stmt = $pdo->prepare(
                'INSERT INTO categories (parent_id, name, slug, is_active, sort_order, created_at, updated_at)
                 VALUES (?, ?, ?, 1, 0, NOW(), NOW())'
            );
            $stmt->execute([$parentId, $name, $slug]);
            return (int) $pdo->lastInsertId();
        };
        $catMainId = $insertCategory(null, 'Ana Kategori Test', 'ana-test');
        $catSubId  = $insertCategory($catMainId, 'Alt Kategori Test', 'alt-test');

        $insertProduct = static function (array $row) use ($pdo): int {
            $stmt = $pdo->prepare(
                'INSERT INTO products
                    (category_id, sku, slug, name, description, brand, barcode, currency, list_price, sale_price,
                     tax_rate, stock, stock_status, status, source_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "TRY", ?, ?, 20.00, ?, ?, ?, "manual", NOW(), NOW())'
            );
            $stmt->execute([
                $row['category_id'] ?? null,
                $row['sku'],
                $row['slug'],
                $row['name'],
                $row['description'] ?? 'Katalog test ürünü açıklaması.',
                $row['brand'] ?? null,
                $row['barcode'] ?? null,
                $row['list_price'] ?? null,
                $row['sale_price'] ?? null,
                $row['stock'],
                ($row['stock'] ?? 0) > 0 ? 'in_stock' : 'out_of_stock',
                $row['status'] ?? 'active',
            ]);
            return (int) $pdo->lastInsertId();
        };

        /** Tedarikçi verisi satırı (last_seen_at bayatlık için kritik). */
        $insertPsd = static function (int $productId, int $supplierId, int $feedId, string $lastSeenAt) use ($pdo): void {
            $stmt = $pdo->prepare(
                'INSERT INTO product_supplier_data
                    (product_id, supplier_id, xml_feed_id, supplier_sku, supplier_price, supplier_currency, stock, last_seen_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 60.0000, "TRY", 10, ?, NOW(), NOW())'
            );
            $stmt->execute([$productId, $supplierId, $feedId, 'PSD-' . $productId, $lastSeenAt]);
        };

        $u1 = $insertProduct(['category_id' => $catMainId, 'sku' => 'CAT-U1', 'slug' => 'cat-u1', 'name' => 'Ahu Kalem Seti Klasik', 'stock' => 5, 'sale_price' => 100.00, 'list_price' => 120.00, 'barcode' => 'BAR8612345']);
        $u2 = $insertProduct(['category_id' => $catMainId, 'sku' => 'CAT-U2', 'slug' => 'cat-u2', 'name' => 'Bulut Defter Büyük', 'brand' => 'Bulut', 'stock' => 0, 'sale_price' => 50.00]);
        $u3 = $insertProduct(['sku' => 'CAT-U3', 'slug' => 'cat-u3', 'name' => 'Silgi Taslak Ürün', 'status' => 'draft', 'stock' => 3, 'sale_price' => 70.00]);
        $u4 = $insertProduct(['category_id' => $catMainId, 'sku' => 'CAT-U4', 'slug' => 'cat-u4', 'name' => 'Bayat Kalemtıraş', 'stock' => 4, 'sale_price' => 80.00]);
        $u5 = $insertProduct(['category_id' => $catSubId, 'sku' => 'CAT-U5', 'slug' => 'cat-u5', 'name' => 'Zeka Küpü Alt Kategori', 'stock' => 2, 'sale_price' => 30.00]);
        $u6 = $insertProduct(['sku' => 'CAT-U6', 'slug' => 'cat-u6', 'name' => 'Silinmiş Ürün', 'stock' => 1, 'sale_price' => 10.00]);

        // Bayatlık verisi: U1/U5 taze, U4 72 saat önce (default eşik 48 saat), U2/U3/U6 tedarikçi kaydı YOK.
        $insertPsd($u1, $supplierId, $feedId, date('Y-m-d H:i:s'));
        $insertPsd($u4, $supplierId, $feedId, date('Y-m-d H:i:s', time() - 72 * 3600));
        $insertPsd($u5, $supplierId, $feedId, date('Y-m-d H:i:s', time() - 3600));

        // Silinmiş ürün (soft delete) — "silinmemiş" filtresinin kanıtı
        (new ProductRepository())->softDelete($u6);

        $state = ['supplierId' => $supplierId, 'feedId' => $feedId, 'catMainId' => $catMainId, 'catSubId' => $catSubId,
                  'u1' => $u1, 'u2' => $u2, 'u3' => $u3, 'u4' => $u4, 'u5' => $u5, 'u6' => $u6];
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

    $u1 = (int) $state['u1'];
    $u2 = (int) $state['u2'];
    $u3 = (int) $state['u3'];
    $u4 = (int) $state['u4'];
    $u5 = (int) $state['u5'];
    $u6 = (int) $state['u6'];
    $catMainId = (int) $state['catMainId'];
    $catSubId = (int) $state['catSubId'];

    /** Servisi TEST DB üzerinde taze üretir (SettingsService önbelleği taze olsun). */
    $service = static function () use ($useDb): StoreProductService {
        $useDb();
        return new StoreProductService();
    };

    /** Ayar servisini test DB üzerinde taze üretir. */
    $settings = static function () use ($useDb): SettingsService {
        $useDb();
        return new SettingsService();
    };

    /** Satır slug listesi. */
    $slugs = static function (array $rows): array {
        return array_map(static fn ($r) => (string) $r['slug'], $rows);
    };

    /* ---------------- testler (kayıt sırası = çalışma sırası) ---------------- */

    $t->addTest('StoreCatalog: search yalnız aktif+silinmemiş ürünleri döndürür (U3 taslak, U6 silinmiş yok)', static function () use ($service, $useDb, $slugs, $u1, $u2, $u3, $u4, $u5, $u6): void {
        $result = $service()->search([], 24, 1);

        Assert::same(4, $result['total'], '4 aktif ürün olmalı (U1,U2,U4,U5)');
        $found = $slugs($result['rows']);
        Assert::count(4, $found);
        Assert::true(in_array('cat-u1', $found, true), 'U1 listede olmalı');
        Assert::true(in_array('cat-u2', $found, true), 'U2 (stoksuz ama aktif) listede olmalı');
        Assert::false(in_array('cat-u3', $found, true), 'Taslak U3 listelenMEMELİ');
        Assert::false(in_array('cat-u6', $found, true), 'Silinmiş U6 listelenMEMELİ');

        // Repository seviyesi: activeProducts de yalnız aktif+silinmemiş döner
        $useDb();
        $rows = (new \App\Repositories\ProductRepository())->activeProducts(24, 0);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        Assert::true(in_array($u1, $ids, true) && in_array($u2, $ids, true) && in_array($u4, $ids, true) && in_array($u5, $ids, true), 'activeProducts U1/U2/U4/U5 içermeli');
        Assert::false(in_array($u3, $ids, true), 'activeProducts taslak U3 içermemeli');
        Assert::false(in_array($u6, $ids, true), 'activeProducts silinmiş U6 içermemeli');

        // Bayatlık işaretleri (disable modu varsayılan): U4 bayat → satın alınamaz
        $bySlug = [];
        foreach ($result['rows'] as $row) {
            $bySlug[(string) $row['slug']] = $row;
        }
        Assert::same(true, $bySlug['cat-u4']['is_stale'], 'U4 bayat işaretlenmeli');
        Assert::same(false, $bySlug['cat-u4']['purchasable'], 'U4 satın alınamaz olmalı (bayat)');
        Assert::same(false, $bySlug['cat-u1']['is_stale'], 'U1 bayat değil');
        Assert::same(true, $bySlug['cat-u1']['purchasable'], 'U1 satın alınabilir');
        Assert::same(false, $bySlug['cat-u2']['purchasable'], 'U2 stoksuz → satın alınamaz');
    });

    $t->addTest('StoreCatalog: disable modunda bayat görünür ama purchasable=false; hide modunda gizlenir', static function () use ($service, $settings, $slugs): void {
        try {
            // disable senaryosu
            $settings()->set('store.stale_stock_action', 'disable');
            $result = $service()->search([], 24, 1);
            Assert::same(4, $result['total'], 'disable modunda bayat ürün LİSTEDE kalmalı');
            $bySlug = [];
            foreach ($result['rows'] as $row) {
                $bySlug[(string) $row['slug']] = $row;
            }
            Assert::true(isset($bySlug['cat-u4']), 'U4 disable modunda görünür olmalı');
            Assert::same(true, $bySlug['cat-u4']['is_stale'], 'U4 is_stale=true');
            Assert::same(false, $bySlug['cat-u4']['purchasable'], 'U4 disable modunda satın alınamaz');

            // hide senaryosu
            $settings()->set('store.stale_stock_action', 'hide');
            $hidden = $service()->search([], 24, 1);
            Assert::same(3, $hidden['total'], 'hide modunda bayat ürün toplamdan düşmeli');
            $found = $slugs($hidden['rows']);
            Assert::false(in_array('cat-u4', $found, true), 'U4 hide modunda gizli olmalı');
        } finally {
            // Varsayılana dön (sonraki testler disable modunu varsayar)
            $settings()->set('store.stale_stock_action', SettingsService::DEFAULTS['store.stale_stock_action']);
        }
    });

    $t->addTest('StoreCatalog: kategori filtresi alt kategoriyi kapsar (descendantIds)', static function () use ($service, $slugs, $catMainId, $catSubId): void {
        $svc = $service();

        $ids = $svc->descendantIds($catMainId);
        Assert::true(in_array($catMainId, $ids, true) && in_array($catSubId, $ids, true), 'descendantIds kök+çocuk içermeli');
        Assert::count(2, $ids);

        $result = $svc->search(['category' => 'ana-test'], 24, 1);
        $found = $slugs($result['rows']);
        Assert::same(4, $result['total'], 'Ana kategori araması alt kategorideki U5\'i de getirmeli');
        Assert::true(in_array('cat-u5', $found, true), 'Alt kategorideki U5 gelmeli');
        Assert::true(in_array('cat-u1', $found, true));

        $sub = $svc->search(['category' => 'alt-test'], 24, 1);
        Assert::same(1, $sub['total'], 'Alt kategori filtresi yalnız U5');
        Assert::same(['cat-u5'], $slugs($sub['rows']));

        $unknown = $svc->search(['category' => 'olmayan-kategori'], 24, 1);
        Assert::same(0, $unknown['total'], 'Bilinmeyen kategori → boş sonuç');
    });

    $t->addTest('StoreCatalog: arama — q ile U1, boş q tümü, barkod ile U1', static function () use ($service, $slugs): void {
        $svc = $service();

        $hit = $svc->search(['q' => 'klasik'], 24, 1);
        Assert::same(1, $hit['total'], 'q=klasik yalnız U1');
        Assert::same(['cat-u1'], $slugs($hit['rows']));

        $empty = $svc->search(['q' => ''], 24, 1);
        Assert::same(4, $empty['total'], 'Boş q → tüm aktif ürünler');

        $barcode = $svc->search(['q' => 'BAR8612345'], 24, 1);
        Assert::same(1, $barcode['total'], 'Barkod araması U1 bulmalı');
        Assert::same(['cat-u1'], $slugs($barcode['rows']));

        $none = $svc->search(['q' => 'hicbirurundeolmayanterimxyz'], 24, 1);
        Assert::same(0, $none['total'], 'Eşleşmeyen terim → 0 sonuç');
    });

    $t->addTest('StoreCatalog: price_asc gerçekten artan + sayfalama (perPage 2 → total/lastPage doğru)', static function () use ($service): void {
        $svc = $service();

        $sorted = $svc->search(['sort' => 'price_asc'], 24, 1);
        $prices = array_map(static fn ($r) => (float) $r['sale_price'], $sorted['rows']);
        Assert::same(4, count($prices));
        $normalized = array_values($prices);
        sort($normalized);
        Assert::same($normalized, $prices, 'price_asc çıktısı artan sırada olmalı');
        Assert::same(30.0, (float) $sorted['rows'][0]['sale_price'], 'En ucuz (U5) ilk sırada');

        $page1 = $svc->search(['sort' => 'price_asc'], 2, 1);
        Assert::same(4, $page1['total'], 'total sayfalama boyutundan bağımsız');
        Assert::same(2, $page1['lastPage'], 'perPage=2 iken lastPage=2');
        Assert::same(1, $page1['page']);
        Assert::count(2, $page1['rows']);
        Assert::same('cat-u5', (string) $page1['rows'][0]['slug'], '1. sayfa ilk satır U5');

        $page2 = $svc->search(['sort' => 'price_asc'], 2, 2);
        Assert::count(2, $page2['rows']);
        Assert::same('cat-u1', (string) $page2['rows'][1]['slug'], '2. sayfa son satır U1 (100.00)');
    });

    $t->addTest('StoreCatalog: staleProductIds doğru id\'yi döndürür; productForStore bayatlık davranışı (disable/hide)', static function () use ($service, $settings, $u1, $u2, $u3, $u4, $u5, $u6): void {
        $svc = $service();

        $stale = $svc->staleProductIds([$u1, $u2, $u3, $u4, $u5, $u6]);
        Assert::same([$u4], $stale, 'Yalnız U4 bayat olmalı (72h > 48h eşiği)');
        Assert::same([], $svc->staleProductIds([]), 'Boş giriş → boş liste');

        // disable modu (varsayılan)
        $settings()->set('store.stale_stock_action', 'disable');
        $svcDisable = $service();

        $detail = $svcDisable->productForStore('cat-u4');
        Assert::true($detail !== null, 'disable modunda bayat ürün detayı NULL OLMAMALI');
        Assert::same(true, $detail['is_stale'], 'U4 is_stale=true');
        Assert::same(false, $detail['purchasable'], 'U4 purchasable=false');
        Assert::true($detail['supplier_last_seen'] !== null, 'supplier_last_seen dolu olmalı');

        $normal = $svcDisable->productForStore('cat-u1');
        Assert::true($normal !== null);
        Assert::same($u1, (int) $normal['id']);
        Assert::same(false, $normal['is_stale']);
        Assert::same(true, $normal['purchasable']);
        Assert::true(($normal['category']['name'] ?? '') === 'Ana Kategori Test', 'Kategori ilişiği gelmeli');
        Assert::same('Ana Kategori Test', (string) ($normal['breadcrumb'][0]['name'] ?? ''), 'Kök kategori breadcrumb\'ın başında');

        Assert::same(null, $svcDisable->productForStore('cat-u3'), 'Taslak ürün detayı NULL olmalı');
        Assert::same(null, $svcDisable->productForStore('olmayan-slug'), 'Bilinmeyen slug → NULL');

        try {
            // hide modu
            $settings()->set('store.stale_stock_action', 'hide');
            $svcHide = $service();
            Assert::same(null, $svcHide->productForStore('cat-u4'), 'hide modunda bayat ürün detayı NULL olmalı');
            Assert::true($svcHide->productForStore('cat-u1') !== null, 'Bayat olmayan ürün hide modunda da görünür');
        } finally {
            $settings()->set('store.stale_stock_action', SettingsService::DEFAULTS['store.stale_stock_action']);
        }
    });

    $t->addTest('StoreCatalog: test DB temizlenir ve config geri yüklenir (stale aksiyonu default\'a döner)', static function () use ($probe, $testDb, $originalDb, $settings): void {
        // Not: kuralın çalışması diğer testlerde kapsanır; burada amaç DÜZENLİ
        // TEMİZLİKTİR. DB bu anda dış bir koşum tarafından düşürülmüş olabilir
        // (paralel runner) — bu durumda da config geri yükleme garanti edilir.
        $check = $probe->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $check->execute([$testDb]);
        $dbExists = (int) $check->fetchColumn() > 0;
        if ($dbExists) {
            try {
                $settings()->set('store.stale_stock_action', SettingsService::DEFAULTS['store.stale_stock_action']);
            } catch (\Throwable) {
                // DB az önce dışarıdan düşürüldüyse sessizce geç
            }
        }
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        Config::set('database.database', $originalDb);
        Database::disconnect();
        Assert::same($originalDb, Config::get('database.database'), 'Orijinal DB adı geri yüklenmeli');
        Assert::same('disable', SettingsService::DEFAULTS['store.stale_stock_action'], 'Varsayılan bayat aksiyonu disable olmalı');
    });
};
