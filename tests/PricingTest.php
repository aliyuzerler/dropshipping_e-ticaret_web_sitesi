<?php

declare(strict_types=1);

/**
 * PricingService testleri — KENDİ test veritabanında (xml_ecommerce_phase2_price_test).
 *
 * Not: TestRunner testleri dosya yüklenme sırasına göre (alfabetik glob) çalıştırır;
 * PricingTest, XmlSyncDbTest'ten önce gelir. Database::pdo() singleton olduğu için
 * her test başında Config::set('database.database', ...) + Database::disconnect()
 * ile kendi test DB'sine yönlendirilir (dosya kendi durumunu kendisi geri yükler).
 * Tam yol: tests/PricingTest.php
 */
use App\Core\Config;
use App\Core\Database;
use App\Database\MigrationRunner;
use App\Services\Pricing\PricingService;

return static function (TestRunner $t): void {
    $host      = (string) Config::get('database.host', '127.0.0.1');
    $port      = (int) Config::get('database.port', 3306);
    $charset   = (string) Config::get('database.charset', 'utf8mb4');
    $username  = (string) Config::get('database.username', 'root');
    $password  = (string) Config::get('database.password', '');
    $originalDb = (string) Config::get('database.database', 'xml_ecommerce');
    $testDb    = 'xml_ecommerce_phase2_price_test';

    /** Her test başında bu DB'ye yönel (pdo singleton'ını bozmadan). */
    $useTestDb = static function () use ($testDb): void {
        Config::set('database.database', $testDb);
        Database::disconnect();
    };

    /** Sunucu erişilemezse tüm testleri SKIP olarak kaydet. */
    $registerSkips = static function (string $reason) use ($t): void {
        $t->addTest('Pricing: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
    };

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

        // Test DB'yi sıfırdan kur (Config override ÖNCE — 024 migration'ı Database::pdo() kullanır)
        $useTestDb();
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        $probe->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $migrationPdo = Database::connect($host, $port, $testDb, $charset, $username, $password);
        (new MigrationRunner($migrationPdo))->runPending();

        // Tohum: bir tedarikçi + parent/child kategori zinciri
        $useTestDb();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (name, code, is_active, created_at, updated_at)
             VALUES (?, ?, 1, NOW(), NOW())'
        );
        $stmt->execute(['Fiyat Test Tedarikçisi', 'PRICE-TEST-SUP']);

        $stmt = $pdo->prepare(
            'INSERT INTO categories (parent_id, name, slug, is_active, sort_order, created_at, updated_at)
             VALUES (NULL, ?, ?, 1, 0, NOW(), NOW())'
        );
        $stmt->execute(['Ofis', 'ofis']);
        $parentId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO categories (parent_id, name, slug, is_active, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, 1, 0, NOW(), NOW())'
        );
        $stmt->execute([$parentId, 'Kalemler', 'kalemler-test']);
        $childId = (int) $pdo->lastInsertId();

        // product scope testi için gerçek bir ürün (price_rules.product_id FK)
        $stmt = $pdo->prepare(
            'INSERT INTO products (sku, slug, name, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute(['PRICE-TEST-1', 'price-test-1', 'Fiyat Test Ürünü']);
        $productId = (int) $pdo->lastInsertId();
    } catch (\Throwable $e) {
        // Kurulum yarıda kaldı: ortamı temizle ve tüm testleri SKIP kaydet
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

    /** Tüm kuralları temizler (her test kendi kuralını kurar). */
    $clearRules = static function () use ($useTestDb): void {
        $useTestDb();
        Database::pdo()->exec('DELETE FROM price_rules');
    };

    /** Tek fiyat kuralı ekler. */
    $addRule = static function (string $type, string $scope, float $value, array $extra = []) use ($useTestDb): int {
        $useTestDb();
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO price_rules
                (name, type, scope, product_id, category_id, supplier_id, value, min_qty, currency, priority, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, "TRY", 0, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'Test kuralı: ' . $type . '/' . $scope,
            $type,
            $scope,
            $extra['product_id'] ?? null,
            $extra['category_id'] ?? null,
            $extra['supplier_id'] ?? null,
            $value,
        ]);
        return (int) $pdo->lastInsertId();
    };

    /** Servisi test DB'si üzerinde üretir. */
    $service = static function () use ($useTestDb): PricingService {
        $useTestDb();
        return new PricingService();
    };

    /** Float karşılaştırma (yuvarlama payı). */
    $approx = static function (float $expected, float $actual, string $message = ''): void {
        Assert::true(
            abs($expected - $actual) < 0.001,
            ($message !== '' ? $message . ' — ' : '') . 'Beklenen ~' . $expected . ', Gelen: ' . $actual
        );
    };

    $t->addTest('Pricing: percent kuralı +%30 → 100 → 130', static function () use ($clearRules, $addRule, $service, $approx): void {
        $clearRules();
        $addRule('percent', 'global', 30.0);
        $result = $service()->compute(100.0);
        $approx(130.0, $result['sale_price'], 'Yüzde kuralı satış fiyatı');
        Assert::count(1, $result['applied'], 'Tek kural uygulanmalı');
    });

    $t->addTest('Pricing: fixed kuralı +25 → 130 → 155', static function () use ($clearRules, $addRule, $service, $approx): void {
        $clearRules();
        $addRule('fixed', 'global', 25.0);
        $approx(155.0, $service()->compute(130.0)['sale_price'], 'Sabit tutar satış fiyatı');
    });

    $t->addTest('Pricing: min_price tabanın altında yükseltilir, üstünde dokunulmaz', static function () use ($clearRules, $addRule, $service, $approx): void {
        $clearRules();
        $addRule('min_price', 'global', 120.0);
        $approx(120.0, $service()->compute(100.0)['sale_price'], 'Taban altı → tabana çekilmeli');
        $approx(150.0, $service()->compute(150.0)['sale_price'], 'Taban üstü → değişmemeli');
    });

    $t->addTest('Pricing: round kuralı adım 1 → tam sayıya yuvarlanır', static function () use ($clearRules, $addRule, $service, $approx): void {
        $clearRules();
        $addRule('round', 'global', 1.0);
        $approx(123.0, $service()->compute(123.456)['sale_price'], 'Adım 1 yuvarlama');
    });

    $t->addTest('Pricing: round_end kuralı 0.99 → x,99 sonu', static function () use ($clearRules, $addRule, $service, $approx): void {
        $clearRules();
        $addRule('round_end', 'global', 0.99);
        $approx(129.99, $service()->compute(130.4)['sale_price'], 'Psikolojik fiyat sonu');
    });

    $t->addTest('Pricing: supplier scope yalnız eşleşen tedarikçiye uygulanır', static function () use ($clearRules, $addRule, $service, $approx): void {
        $clearRules();
        $addRule('percent', 'supplier', 50.0, ['supplier_id' => 1]);
        $approx(150.0, $service()->compute(100.0, null, 1)['sale_price'], 'Eşleşen tedarikçi');
        $approx(100.0, $service()->compute(100.0, null, 2)['sale_price'], 'Eşleşmeyen tedarikçi — kural uygulanmamalı');
    });

    $t->addTest('Pricing: category scope parent zinciriyle alt kategoriye iner', static function () use ($useTestDb, $clearRules, $addRule, $service, $approx, $childId, $parentId): void {
        $clearRules();
        $addRule('percent', 'category', 20.0, ['category_id' => $parentId]);

        $parentMap = [$parentId => null, $childId => $parentId];
        $approx(120.0, $service()->compute(100.0, $childId, null, null, 20.0, $parentMap)['sale_price'],
            'Alt kategori, parent kuralını miras almalı');

        // Zincir verilmemişse eşleşme olmamalı
        $approx(100.0, $service()->compute(100.0, $childId, null, null, 20.0, null)['sale_price'],
            'parentMap yokken kategori zinciri takip edilemez');
    });

    $t->addTest('Pricing: product scope yalnız hedef ürüne uygulanır', static function () use ($clearRules, $addRule, $service, $approx, $productId): void {
        $clearRules();
        $addRule('fixed', 'product', 50.0, ['product_id' => $productId]);
        $approx(150.0, $service()->compute(100.0, null, null, $productId)['sale_price'], 'Eşleşen ürün');
        $approx(100.0, $service()->compute(100.0, null, null, $productId + 1)['sale_price'], 'Başka ürün → kural uygulanmamalı');
    });

    $t->addTest('Pricing: kâr eşiği altında low_margin true', static function () use ($clearRules, $addRule, $service): void {
        $threshold = (float) Config::get('xml.pricing.profit_warn_percent', 10.0);

        $clearRules();
        $noRule = $service()->compute(100.0);
        Assert::true($noRule['low_margin'], 'Kural yokken marj 0 → low_margin true olmalı');

        $clearRules();
        $addRule('percent', 'global', 30.0);
        $withRule = $service()->compute(100.0);
        Assert::same(false, $withRule['low_margin'], 'Marj eşiğin üstünde → low_margin false olmalı');
        Assert::true($withRule['margin'] > $threshold, 'Marj eşiğin üstünde olmalı: ' . $withRule['margin']);
    });

    $t->addTest('Pricing: kural yokken satış fiyatı = alış fiyatı', static function () use ($clearRules, $service, $approx): void {
        $clearRules();
        $result = $service()->compute(42.5);
        $approx(42.5, $result['sale_price'], 'Kuralsız → satış = alış');
        Assert::count(0, $result['applied'], 'Hiçbir kural uygulanmamalı');
    });

    $t->addTest('Pricing: test DB temizlenir ve config geri yüklenir', static function () use ($probe, $testDb, $originalDb): void {
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        Config::set('database.database', $originalDb);
        Database::disconnect();
        Assert::same($originalDb, Config::get('database.database'), 'Orijinal DB adı geri yüklenmeli');
    });
};
