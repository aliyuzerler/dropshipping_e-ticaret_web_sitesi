<?php

declare(strict_types=1);

/**
 * Aşama 3 — Sepet servis testleri (CartService + CouponService).
 *
 * KENDİ test veritabanında (xml_ecommerce_test3_cart) sıfırdan migration +
 * tohumlama ile çalışır. Kapsam: misafir/üye sepet çözümleme, stok koruması,
 * sunucu taraflı fiyat hesabı (summary), kargo eşiği, kupon uygulama/kaldırma,
 * updateQuantity/remove/clear ve misafir sepetinin üye sepetiyle birleştirilmesi.
 *
 * Session notu: Session dosya tabanlıdır; CLI'da güvenli bir kayıt yolu ile
 * bir kez başlatılır. Her test başında session durumu (customer_id / cart_token)
 * Session::set/Session::remove ile senkronlanır; dosya sonunda temizlenir.
 * Tam yol: tests/CartTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Database\MigrationRunner;
use App\Exceptions\ValidationException;
use App\Repositories\CartRepository;
use App\Services\Store\CartService;
use App\Services\Store\SettingsService;

return static function (TestRunner $t): void {
    $host       = (string) Config::get('database.host', '127.0.0.1');
    $port       = (int) Config::get('database.port', 3306);
    $charset    = (string) Config::get('database.charset', 'utf8mb4');
    $username   = (string) Config::get('database.username', 'root');
    $password   = (string) Config::get('database.password', '');
    $originalDb = (string) (env('DB_DATABASE') ?: 'xml_ecommerce'); // env tabanlı: önceki test dosyalarının Config::set sızıntısından bağışık
    $testDb     = $originalDb . '_test3_cart';

    /* ---------- Session CLI kurulumu (dosya yükleme anında, bir kez) ---------- */
    // run.php çıktısından sonra CLI'da gerçek session_start başarısızdır
    // ("headers already sent"); uyarılar bastırılarak denenir. Başarısız olsa
    // bile Session API'si süreç içi $_SESSION dizisiyle çalışır (CsrfTest de
    // aynı varsayımla çalışır); session_regenerate gibi çağrılar no-op'tur.
    $sessLevel = error_reporting(error_reporting() & ~E_WARNING);
    $sessPath = sys_get_temp_dir() . '/xmlshop-test-sessions';
    if (!is_dir($sessPath)) {
        @mkdir($sessPath, 0700, true);
    }
    @session_save_path($sessPath);
    Session::start();
    error_reporting($sessLevel);
    if (!is_array($_SESSION ?? null)) {
        $_SESSION = []; // session başlatılamadıysa süreç içi dizi garantile
    }

    $registerSkips = static function (string $reason) use ($t): void {
        $t->addTest('Cart: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
    };

    /** Her test/yardımcı başında bu dosyanın DB'sine yönel. */
    $useDb = static function () use ($testDb): void {
        Config::set('database.database', $testDb);
        Database::disconnect();
    };

    $exec = static function (string $sql, array $params = []) use ($useDb): int {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) Database::pdo()->lastInsertId();
    };

    $scalar = static function (string $sql, array $params = []) use ($useDb): mixed {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    };

    $approx = static function (float $expected, mixed $actual, string $message = ''): void {
        $actualFloat = (float) $actual;
        Assert::true(
            abs($expected - $actualFloat) < 0.005,
            ($message !== '' ? $message . ' — ' : '') . 'Beklenen ~' . $expected . ', Gelen: ' . $actualFloat
        );
    };

    /** Deterministik 64 haneli oturum token'ı (test başına ayrı sepet). */
    $token = static fn (string $name): string => hash('sha256', 'cart-test::' . $name);

    /** Session durumunu temizler (her test başında senkron). */
    $resetSession = static function (): void {
        Session::remove('customer_id');
        Session::remove('customer_email');
        Session::remove('customer_name');
        Session::remove('cart_token');
    };

    /** ValidationException bekler; hata anahtarlarını doğrular. */
    $expectErrors = static function (callable $fn, array $keys, string $message = ''): array {
        try {
            $fn();
        } catch (ValidationException $e) {
            foreach ($keys as $key) {
                Assert::true(
                    isset($e->errors()[$key]),
                    ($message !== '' ? $message . ' — ' : '') . "Hata anahtarı beklenmişti: $key"
                );
            }
            return $e->errors();
        }
        throw new RuntimeException(($message !== '' ? $message . ' — ' : '') . 'ValidationException fırlatılmalıydı');
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
        $stmt->execute(['Sepet Test Tedarikçisi', 'SUP-CART-TEST']);

        $insertProduct = static function (array $row) use ($pdo): int {
            $stmt = $pdo->prepare(
                'INSERT INTO products
                    (sku, slug, name, currency, list_price, sale_price, tax_rate, stock, stock_status, status, source_type, created_at, updated_at)
                 VALUES (?, ?, ?, "TRY", ?, ?, 20.00, ?, ?, ?, "manual", NOW(), NOW())'
            );
            $stmt->execute([
                $row['sku'],
                $row['slug'],
                $row['name'],
                $row['list_price'] ?? null,
                $row['sale_price'] ?? null,
                $row['stock'],
                ($row['stock'] ?? 0) > 0 ? 'in_stock' : 'out_of_stock',
                $row['status'] ?? 'active',
            ]);
            return (int) $pdo->lastInsertId();
        };

        $u1 = $insertProduct(['sku' => 'CART-U1', 'slug' => 'cart-u1', 'name' => 'Sepet Test Kalemi', 'stock' => 3, 'sale_price' => 100.00, 'list_price' => 120.00]);
        $u2 = $insertProduct(['sku' => 'CART-U2', 'slug' => 'cart-u2', 'name' => 'Sepet Test Defteri', 'stock' => 10, 'sale_price' => 50.00]);
        $u3 = $insertProduct(['sku' => 'CART-U3', 'slug' => 'cart-u3', 'name' => 'Sepet Test Varyantlı', 'stock' => 5, 'sale_price' => 60.00]);
        $u4 = $insertProduct(['sku' => 'CART-U4', 'slug' => 'cart-u4', 'name' => 'Sepet Stoksuz Ürün', 'stock' => 0, 'sale_price' => 20.00]);
        $u5 = $insertProduct(['sku' => 'CART-U5', 'slug' => 'cart-u5', 'name' => 'Sepet Taslak Ürün', 'status' => 'draft', 'stock' => 5, 'sale_price' => 15.00]);

        // U3 varyantı: v1 (fiyat 25.00, stok 2)
        $stmt = $pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, attributes, price, stock, status, created_at, updated_at)
             VALUES (?, "CART-U3-V1", ?, 25.0000, 2, "active", NOW(), NOW())'
        );
        $stmt->execute([$u3, json_encode(['beden' => 'S'], JSON_UNESCAPED_UNICODE)]);
        $v1 = (int) $pdo->lastInsertId();

        // Kuponlar: HOSGELDIN10 (percent 10, min 200) + KUPONBITMIS (süresi dolmuş)
        $stmt = $pdo->prepare(
            'INSERT INTO coupons (code, description, type, value, min_order_total, is_active, created_at, updated_at)
             VALUES ("HOSGELDIN10", "Test hoş geldin kuponu", "percent", 10.00, 200.00, 1, NOW(), NOW())'
        );
        $stmt->execute();
        $couponId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO coupons (code, description, type, value, min_order_total, expires_at, is_active, created_at, updated_at)
             VALUES ("KUPONBITMIS", "Süresi dolmuş test kuponu", "percent", 10.00, 0.00, DATE_SUB(NOW(), INTERVAL 1 DAY), 1, NOW(), NOW())'
        );
        $stmt->execute();

        $state = ['u1' => $u1, 'u2' => $u2, 'u3' => $u3, 'u4' => $u4, 'u5' => $u5, 'v1' => $v1, 'couponId' => $couponId];
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
    $v1 = (int) $state['v1'];
    $couponId = (int) $state['couponId'];

    /** Servisi TEST DB üzerinde taze üretir. */
    $cart = static function () use ($useDb): CartService {
        $useDb();
        return new CartService();
    };

    $settings = static function () use ($useDb): SettingsService {
        $useDb();
        return new SettingsService();
    };

    /* ---------------- testler (kayıt sırası = çalışma sırası) ---------------- */

    $t->addTest('Cart: misafir sepeti oluşturur — carts.customer_id NULL kalır', static function () use ($cart, $resetSession, $token, $scalar, $u1): void {
        $resetSession();
        Session::set('cart_token', $token('guest'));
        $svc = $cart();

        $svc->add($u1, null, 1);

        $resolved = $svc->resolve(false);
        Assert::true($resolved !== null, 'Misafir sepeti çözümlenmeli');
        $cartId = (int) $resolved['id'];
        Assert::same(null, $scalar('SELECT customer_id FROM carts WHERE id = ?', [$cartId]), 'Misafir sepetinde customer_id NULL olmalı');
        Assert::same('active', (string) $scalar('SELECT status FROM carts WHERE id = ?', [$cartId]));
        Assert::same(1, (int) $scalar('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$cartId]));
    });

    $t->addTest('Cart: aynı ürün tekrar eklenirse adet artar, satır çoğalmaz', static function () use ($cart, $resetSession, $token, $scalar, $u1): void {
        $resetSession();
        Session::set('cart_token', $token('repeat'));
        $svc = $cart();

        $svc->add($u1, null, 1);
        $svc->add($u1, null, 2);

        $cartId = (int) $svc->resolve(false)['id'];
        Assert::same(1, (int) $scalar('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$cartId]), 'Tek satır olmalı');
        Assert::same(3, (int) $scalar('SELECT quantity FROM cart_items WHERE cart_id = ?', [$cartId]), 'Adet 1+2=3 olmalı (stok 3)');
        Assert::same(3, $svc->itemCount());
    });

    $t->addTest('Cart: stok üstü, stoksuz ve taslak ürün ValidationException verir', static function () use ($cart, $resetSession, $token, $expectErrors, $u1, $u4, $u5, $u3, $v1): void {
        $resetSession();
        Session::set('cart_token', $token('stock-guard'));
        $svc = $cart();

        // Stok üstü (stok 3 → 4 adet reddedilir)
        $expectErrors(static fn () => $svc->add($u1, null, 4), ['quantity'], 'Stok üstü ek');
        // Stokta yok
        $expectErrors(static fn () => $svc->add($u4, null, 1), ['cart'], 'Stoksuz ürün ek');
        // Taslak ürün
        $expectErrors(static fn () => $svc->add($u5, null, 1), ['cart'], 'Taslak ürün ek');
        // Varyant stok üstü (v1 stok 2)
        $svc->add($u3, $v1, 2);
        $expectErrors(static fn () => $svc->add($u3, $v1, 1), ['quantity'], 'Varyant stok üstü ek');
        // Geçersiz adet
        $expectErrors(static fn () => $svc->add($u1, null, 0), ['quantity'], 'Adet 0');
    });

    $t->addTest('Cart: summary SUNUCU TARAFLI fiyat kullanır — cart_items.unit_price bozulsa bile DB sale_price geçerli', static function () use ($cart, $resetSession, $token, $exec, $approx, $u1, $u3, $v1, $scalar): void {
        $resetSession();
        Session::set('cart_token', $token('server-price'));
        $svc = $cart();

        $svc->add($u1, null, 1);
        $svc->add($u3, $v1, 1);

        $cartId = (int) $svc->resolve(false)['id'];
        // Sakin olmayan senaryo: ekleme anındaki fiyat elle bozulur
        $exec('UPDATE cart_items SET unit_price = 999.9900 WHERE cart_id = ?', [$cartId]);

        $summary = $svc->summary();
        Assert::count(2, $summary['items']);
        $byProduct = [];
        foreach ($summary['items'] as $item) {
            $byProduct[(int) $item['product_id']] = $item;
        }
        $approx(100.00, $byProduct[$u1]['unit_price'], 'U1 birim fiyat DB sale_price\'dan gelmeli (999.99 DEĞİL)');
        $approx(25.00, $byProduct[$u3]['unit_price'], 'Varyant birim fiyatı varyant fiyatından gelmeli');
        $approx(100.00, $byProduct[$u1]['line_total']);
        $approx(25.00, $byProduct[$u3]['line_total']);
        $approx(125.00, $summary['subtotal'], 'Ara toplam sunucuda hesaplanmalı');
        $approx(174.90, $summary['grand_total'], '125.00 + kargo 49.90 = 174.90');
        Assert::same(2, $summary['count']);
        Assert::same('TRY', $summary['currency']);
        // Kalem satırındaki bozuk fiyat DB'de hâlâ boş değildir ama kullanılmaz:
        Assert::same(999.9900, (float) $scalar('SELECT unit_price FROM cart_items WHERE cart_id = ? AND product_id = ?', [$cartId, $u1]), 'Bozuk kayıt tespit edilebilir olmalı');
    });

    $t->addTest('Cart: kargo — 750 altı 49.90, eşik üstü 0 (SettingsService threshold ile)', static function () use ($cart, $resetSession, $token, $approx, $settings, $u2): void {
        $resetSession();
        Session::set('cart_token', $token('shipping'));
        $svc = $cart();

        $svc->add($u2, null, 6); // 6 × 50 = 300 < 750

        $summary = $svc->summary();
        $approx(300.00, $summary['subtotal']);
        $approx(49.90, $summary['shipping_total'], 'Eşik altı → kargo ücreti');
        $approx(450.00, $summary['free_shipping_remaining'], 'Ücretsiz kargoya kalan');
        $approx(349.90, $summary['grand_total']);

        try {
            // Eşiği 250'ye çek → ücretsiz kargo
            $settings()->set('store.free_shipping_threshold', '250.00');
            $free = $cart()->summary();
            $approx(0.00, $free['shipping_total'], 'Eşik üstü → kargo 0');
            $approx(0.00, $free['free_shipping_remaining']);
            $approx(300.00, $free['grand_total']);

            // Ücreti de özelleştir → kargo tutarı ayardan gelir
            $settings()->set('store.shipping_fee', '39.90');
            $settings()->set('store.free_shipping_threshold', '750.00');
            $custom = $cart()->summary();
            $approx(39.90, $custom['shipping_total'], 'shipping_fee ayarı uygulanmalı');
        } finally {
            $settings()->set('store.shipping_fee', SettingsService::DEFAULTS['store.shipping_fee']);
            $settings()->set('store.free_shipping_threshold', SettingsService::DEFAULTS['store.free_shipping_threshold']);
        }
    });

    $t->addTest('Cart: updateQuantity stok üstü hata; 0 → satır silinir; remove/clear çalışır', static function () use ($cart, $resetSession, $token, $expectErrors, $approx, $scalar, $u1, $u2): void {
        $resetSession();
        Session::set('cart_token', $token('update-remove'));
        $svc = $cart();

        $svc->add($u1, null, 1);
        $summary = $svc->summary();
        $itemId = (int) $summary['items'][0]['id'];

        // Stok üstü (stok 3)
        $expectErrors(static fn () => $svc->updateQuantity($itemId, 4), ['quantity'], 'Stok üstü güncelleme');
        // Sınır: 3 ok
        $svc->updateQuantity($itemId, 3);
        $approx(300.00, $svc->summary()['subtotal']);
        // 0 → satır silinir
        $svc->updateQuantity($itemId, 0);
        Assert::same(0, $svc->itemCount(), 'Adet 0 → satır silinmeli');

        // remove
        $svc->add($u1, null, 1);
        $svc->add($u2, null, 1);
        $rows = $svc->summary()['items'];
        Assert::count(2, $rows);
        $u2ItemId = (int) (array_values(array_filter($rows, static fn ($r) => (int) $r['product_id'] === $u2))[0])['id'];
        $svc->remove($u2ItemId);
        Assert::same(1, $svc->itemCount(), 'remove sonrası tek kalem');

        // clear: kupon da temizlenmeli
        $svc->add($u1, null, 2); // toplam U1 3 adet = 300
        $svc->applyCoupon('HOSGELDIN10');
        $cartId = (int) $svc->resolve(false)['id'];
        Assert::same('HOSGELDIN10', (string) $scalar('SELECT coupon_code FROM carts WHERE id = ?', [$cartId]));
        $svc->clear();
        Assert::same(0, $svc->itemCount(), 'clear tüm satırları silmeli');
        Assert::same(null, $scalar('SELECT coupon_code FROM carts WHERE id = ?', [$cartId]), 'clear kuponu da temizlemeli');
    });

    $t->addTest('Cart: kupon — percent 10/min 200 uygulanır; min altı, süresi dolmuş, bilinmeyen ve boş sepet reddedilir; removeCoupon temizler', static function () use ($cart, $resetSession, $token, $expectErrors, $approx, $scalar, $u1): void {
        $resetSession();
        Session::set('cart_token', $token('coupon-ok'));
        $svc = $cart();

        // Ara toplam 300 ≥ 200 → kupon uygulanır (kod küçük harfle verilir, DB'ye büyük yazılır)
        $svc->add($u1, null, 3);
        $couponRow = $svc->applyCoupon('hosgeldin10');
        Assert::same('percent', (string) $couponRow['type']);
        $cartId = (int) $svc->resolve(false)['id'];
        Assert::same('HOSGELDIN10', (string) $scalar('SELECT coupon_code FROM carts WHERE id = ?', [$cartId]));

        $summary = $svc->summary();
        $approx(30.00, $summary['discount_total'], '300 × %10 = 30.00 indirim');
        $approx(319.90, $summary['grand_total'], '300 − 30 + 49.90 kargo');
        Assert::true($summary['coupon'] !== null, 'Özette kupon bilgisi dönmeli');
        Assert::same(0, (int) $scalar('SELECT used_count FROM coupons WHERE id = ?', [$couponId]), 'Kullanım sayacı yalnız CHECKOUT\'ta artmalı');

        $svc->removeCoupon();
        Assert::same(null, $scalar('SELECT coupon_code FROM carts WHERE id = ?', [$cartId]), 'removeCoupon kupon kodunu temizlemeli');
        $approx(0.00, $cart()->summary()['discount_total']);

        // Min altı: 100 < 200
        $resetSession();
        Session::set('cart_token', $token('coupon-min'));
        $svcMin = $cart();
        $svcMin->add($u1, null, 1);
        $expectErrors(static fn () => $svcMin->applyCoupon('HOSGELDIN10'), ['coupon'], 'Min sepet tutarı altı');

        // Süresi dolmuş kupon
        $resetSession();
        Session::set('cart_token', $token('coupon-expired'));
        $svcExp = $cart();
        $svcExp->add($u1, null, 3);
        $expectErrors(static fn () => $svcExp->applyCoupon('KUPONBITMIS'), ['coupon'], 'Süresi dolmuş kupon');

        // Bilinmeyen kod
        $expectErrors(static fn () => $svcExp->applyCoupon('YOKBOYLEKUPON'), ['coupon'], 'Bilinmeyen kod');

        // Boş sepette kupon
        $resetSession();
        Session::set('cart_token', $token('coupon-empty'));
        $svcEmpty = $cart();
        $expectErrors(static fn () => $svcEmpty->applyCoupon('HOSGELDIN10'), ['coupon'], 'Boş sepette kupon');
    });

    $t->addTest('Cart: mergeGuestCartIntoCustomer — misafir sepeti üye sepetiyle birleşir; ikinci merge idempotent', static function () use ($cart, $resetSession, $token, $exec, $scalar, $u1, $u2): void {
        $resetSession();

        // 1) Üye ve BOŞ bir üye sepeti (ayrı session token) hazırla
        $customerId = $exec(
            'INSERT INTO customers (first_name, last_name, email, password_hash, status, created_at, updated_at)
             VALUES ("Merge", "Müşterisi", "merge-cart@example.test", NULL, "active", NOW(), NOW())'
        );
        $exec(
            'INSERT INTO carts (customer_id, session_token, status, currency, created_at, updated_at)
             VALUES (?, ?, "active", "TRY", NOW(), NOW())',
            [$customerId, $token('merge-member')]
        );

        // 2) Misafir sepeti: U1×2 + U2×1 (ayrı session token, customer_id yok)
        $guestToken = $token('merge-guest');
        Session::set('cart_token', $guestToken);
        $svc = $cart();
        $svc->add($u1, null, 2);
        $svc->add($u2, null, 1);
        $guestCartId = (int) $svc->resolve(false)['id'];
        Assert::same(null, $scalar('SELECT customer_id FROM carts WHERE id = ?', [$guestCartId]));

        // 3) Üye olarak giriş yapılmış gibi davran ve üye sepetine U1×1 ekle
        Session::set('customer_id', $customerId);
        $svc->add($u1, null, 1); // resolve → findActiveByCustomer → üye sepeti
        $memberCartId = (int) $svc->resolve(false)['id'];
        Assert::true($memberCartId !== $guestCartId, 'Üye ve misafir sepetleri ayrı olmalı');
        Assert::same($customerId, (int) $scalar('SELECT customer_id FROM carts WHERE id = ?', [$memberCartId]));

        // 4) Birleştir: U1 → min(stok 3, 1+2)=3, U2 → 1 eklenir; misafir sepeti converted
        $svc->mergeGuestCartIntoCustomer($customerId);

        Assert::same(1, (int) $scalar(
            "SELECT COUNT(*) FROM carts WHERE customer_id = ? AND status = 'active'",
            [$customerId]
        ), 'Üyenin TEK aktif sepeti olmalı');
        Assert::same('converted', (string) $scalar('SELECT status FROM carts WHERE id = ?', [$guestCartId]), 'Misafir sepeti converted olmalı');

        $items = $scalar('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$memberCartId]);
        Assert::same(2, (int) $items, 'Üye sepetinde 2 satır olmalı');
        $qty = static fn (int $pid): int => (int) $scalar(
            'SELECT quantity FROM cart_items WHERE cart_id = ? AND product_id = ?',
            [$memberCartId, $pid]
        );
        Assert::same(3, $qty($u1), 'U1 adetleri birleşmeli (1+2, stok 3 ile sınırlı)');
        Assert::same(1, $qty($u2), 'U2 misafirden aktarılmalı');

        // 5) İkinci merge idempotent — misafir sepeti converted olduğundan hiçbir şey değişmemeli
        $svc->mergeGuestCartIntoCustomer($customerId);
        Assert::same(2, (int) $scalar('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$memberCartId]), 'İkinci merge satır EKLEMEMELİ');
        Assert::same(3, $qty($u1), 'İkinci merge U1 adedini DEĞİŞTİRMEMELİ');
        Assert::same(1, $qty($u2), 'İkinci merge U2 adedini DEĞİŞTİRMEMELİ');
        Assert::same(1, (int) $scalar("SELECT COUNT(*) FROM carts WHERE customer_id = ? AND status = 'active'", [$customerId]));

        $resetSession();
    });

    $t->addTest('Cart: test DB temizlenir, config geri yüklenir, oturum temizlenir', static function () use ($probe, $testDb, $originalDb, $resetSession, $settings): void {
        $settings()->set('store.shipping_fee', SettingsService::DEFAULTS['store.shipping_fee']);
        $settings()->set('store.free_shipping_threshold', SettingsService::DEFAULTS['store.free_shipping_threshold']);
        $resetSession();
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        Config::set('database.database', $originalDb);
        Database::disconnect();
        Assert::same($originalDb, Config::get('database.database'), 'Orijinal DB adı geri yüklenmeli');
        Assert::same(null, Session::get('customer_id'), 'customer_id oturumdan silinmiş olmalı');
        Assert::same(null, Session::get('cart_token'), 'cart_token oturumdan silinmiş olmalı');
    });
};
