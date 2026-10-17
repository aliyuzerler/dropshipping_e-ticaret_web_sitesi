<?php

declare(strict_types=1);

/**
 * Aşama 3 — Checkout / sipariş oluşturma servis testleri (CheckoutService).
 *
 * KENDİ test veritabanında (xml_ecommerce_test3_checkout) sıfırdan migration +
 * tohumlama ile çalışır. Kapsam: mutlu yol (sipariş + snapshot + stok düşümü +
 * sepet kapanışı + kupon kullanımı), stok yetersizliğinde ROLLBACK, checkout
 * sırasındaki fiyat değişiminin yeni fiattan snapshot alınması, doğrulama
 * hataları (yanlış address_id, terms onaysız, boş sepet) ve sepetteki bayat
 * ürünün siparişi reddetmesi.
 *
 * Session notu: placeOrder oturumdaki customer_id'yi okur; her test başında
 * Session::set ile senkronlanır, dosya sonunda temizlenir.
 * Tam yol: tests/CheckoutOrderTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Database\MigrationRunner;
use App\Exceptions\ValidationException;
use App\Services\Store\CartService;
use App\Services\Store\CheckoutService;
use App\Services\Store\SettingsService;

return static function (TestRunner $t): void {
    $host       = (string) Config::get('database.host', '127.0.0.1');
    $port       = (int) Config::get('database.port', 3306);
    $charset    = (string) Config::get('database.charset', 'utf8mb4');
    $username   = (string) Config::get('database.username', 'root');
    $password   = (string) Config::get('database.password', '');
    $originalDb = (string) (env('DB_DATABASE') ?: 'xml_ecommerce'); // env tabanlı: önceki test dosyalarının Config::set sızıntısından bağışık
    $testDb     = $originalDb . '_test3_checkout';

    /* ---------- Session CLI kurulumu (dosya yükleme anında, bir kez) ---------- */
    // run.php çıktısından sonra CLI'da gerçek session_start başarısızdır;
    // uyarılar bastırılır, $_SESSION süreç içi dizi olarak garantilenir.
    $sessLevel = error_reporting(error_reporting() & ~E_WARNING);
    $sessPath = sys_get_temp_dir() . '/xmlshop-test-sessions';
    if (!is_dir($sessPath)) {
        @mkdir($sessPath, 0700, true);
    }
    @session_save_path($sessPath);
    Session::start();
    error_reporting($sessLevel);
    if (!is_array($_SESSION ?? null)) {
        $_SESSION = [];
    }

    $registerSkips = static function (string $reason) use ($t): void {
        $t->addTest('Checkout: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
    };

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

    $one = static function (string $sql, array $params = []) use ($useDb): ?array {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    };

    $approx = static function (float $expected, mixed $actual, string $message = ''): void {
        $actualFloat = (float) $actual;
        Assert::true(
            abs($expected - $actualFloat) < 0.005,
            ($message !== '' ? $message . ' — ' : '') . 'Beklenen ~' . $expected . ', Gelen: ' . $actualFloat
        );
    };

    $token = static fn (string $name): string => hash('sha256', 'checkout-test::' . $name);

    $resetSession = static function (): void {
        Session::remove('customer_id');
        Session::remove('customer_email');
        Session::remove('customer_name');
        Session::remove('cart_token');
    };

    /** Geçerli yeni-adres girdisi (terms dahil). */
    $validInput = static function (array $overrides = []): array {
        return [
            'address_mode' => 'new',
            'full_name'    => 'Test Alıcı',
            'city'         => 'İstanbul',
            'district'     => 'Kadıköy',
            'postal_code'  => '34710',
            'line1'        => 'Test Mah. 1. Sk. No:3',
            'phone'        => '+90 555 000 00 00',
            'terms'        => '1',
            ...$overrides,
        ];
    };

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
        $stmt->execute(['Checkout Test Tedarikçisi', 'SUP-CHK-TEST']);
        $supplierId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO customers (first_name, last_name, email, password_hash, status, created_at, updated_at)
             VALUES ("Checkout", "Müşterisi", "checkout@example.test", NULL, "active", NOW(), NOW())'
        );
        $stmt->execute();
        $customerId = (int) $pdo->lastInsertId();

        // address_mode=existing senaryosu için kayıtlı adres
        $stmt = $pdo->prepare(
            'INSERT INTO addresses
                (customer_id, title, type, full_name, country_code, city, district, postal_code, line1, line2, phone, is_default, created_at, updated_at)
             VALUES (?, "Ev Adresim", "shipping", "Checkout Müşterisi", "TR", "İstanbul", "Beşiktaş", "34357",
                     "Barbaros Blv. No:5", NULL, "+90 555 999 88 77", 1, NOW(), NOW())'
        );
        $stmt->execute([$customerId]);
        $addressId = (int) $pdo->lastInsertId();

        $insertProduct = static function (array $row) use ($pdo): int {
            $stmt = $pdo->prepare(
                'INSERT INTO products
                    (sku, slug, name, currency, sale_price, tax_rate, stock, stock_status, status, source_type, created_at, updated_at)
                 VALUES (?, ?, ?, "TRY", ?, 20.00, ?, ?, ?, "manual", NOW(), NOW())'
            );
            $stmt->execute([
                $row['sku'],
                $row['slug'],
                $row['name'],
                $row['sale_price'],
                $row['stock'],
                $row['stock'] > 0 ? 'in_stock' : 'out_of_stock',
                $row['status'] ?? 'active',
            ]);
            return (int) $pdo->lastInsertId();
        };

        $insertPsd = static function (int $productId, int $supplierId) use ($pdo): void {
            $stmt = $pdo->prepare(
                'INSERT INTO product_supplier_data
                    (product_id, supplier_id, supplier_sku, supplier_price, supplier_currency, stock, last_seen_at, created_at, updated_at)
                 VALUES (?, ?, ?, 30.0000, "TRY", 50, NOW(), NOW(), NOW())'
            );
            $stmt->execute([$productId, $supplierId, 'PSD-' . $productId]);
        };

        $u1 = $insertProduct(['sku' => 'CHK-U1', 'slug' => 'chk-u1', 'name' => 'Checkout Kalemi', 'stock' => 5, 'sale_price' => 100.00]);
        $u2 = $insertProduct(['sku' => 'CHK-U2', 'slug' => 'chk-u2', 'name' => 'Checkout Defteri', 'stock' => 10, 'sale_price' => 50.00]);
        $u4 = $insertProduct(['sku' => 'CHK-U4', 'slug' => 'chk-u4', 'name' => 'Checkout Son Adet', 'stock' => 1, 'sale_price' => 40.00]);
        $u5 = $insertProduct(['sku' => 'CHK-U5', 'slug' => 'chk-u5', 'name' => 'Checkout Fiyat Değişen', 'stock' => 5, 'sale_price' => 60.00]);
        $u6 = $insertProduct(['sku' => 'CHK-U6', 'slug' => 'chk-u6', 'name' => 'Checkout Bayat Olacak', 'stock' => 5, 'sale_price' => 20.00]);

        foreach ([$u1, $u2, $u4, $u5, $u6] as $pid) {
            $insertPsd($pid, $supplierId);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO coupons (code, description, type, value, min_order_total, is_active, created_at, updated_at)
             VALUES ("HOSGELDIN10", "Checkout test kuponu", "percent", 10.00, 200.00, 1, NOW(), NOW())'
        );
        $stmt->execute();
        $couponId = (int) $pdo->lastInsertId();

        $state = ['supplierId' => $supplierId, 'customerId' => $customerId, 'addressId' => $addressId,
                  'u1' => $u1, 'u2' => $u2, 'u4' => $u4, 'u5' => $u5, 'u6' => $u6, 'couponId' => $couponId];
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

    $supplierId = (int) $state['supplierId'];
    $customerId = (int) $state['customerId'];
    $addressId  = (int) $state['addressId'];
    $u1 = (int) $state['u1'];
    $u2 = (int) $state['u2'];
    $u4 = (int) $state['u4'];
    $u5 = (int) $state['u5'];
    $u6 = (int) $state['u6'];
    $couponId = (int) $state['couponId'];

    /** Sepet servisini TEST DB üzerinde taze üretir. */
    $cart = static function () use ($useDb): CartService {
        $useDb();
        return new CartService();
    };

    $checkout = static function () use ($useDb): CheckoutService {
        $useDb();
        return new CheckoutService();
    };

    $settings = static function () use ($useDb): SettingsService {
        $useDb();
        return new SettingsService();
    };

    /** Müşteri oturumunu açar + sepet token'ı atar. */
    $loginCustomer = static function (string $name) use ($resetSession, $token, $customerId): void {
        $resetSession();
        Session::set('customer_id', $customerId);
        Session::set('cart_token', $token($name));
    };

    /* ---------------- testler (kayıt sırası = çalışma sırası) ---------------- */

    $t->addTest('Checkout: placeOrder mutlu yol — sipariş, kalem snapshot\'ları, stok düşümü, sepet completed, kupon used_count', static function () use ($cart, $checkout, $loginCustomer, $validInput, $approx, $one, $scalar, $supplierId, $u1, $u2, $u4, $couponId): void {
        $loginCustomer('happy');
        $svcCart = $cart();

        // Sepet: 2×100 + 2×50 + 1×40 = 340.00
        $svcCart->add($u1, null, 2);
        $svcCart->add($u2, null, 2);
        $svcCart->add($u4, null, 1);
        $svcCart->applyCoupon('HOSGELDIN10');

        $cartId = (int) $svcCart->resolve(false)['id'];
        $result = $checkout()->placeOrder($validInput(), '127.0.0.1', 'TestRunner/1.0');

        Assert::true((int) $result['order_id'] > 0, 'order_id dönmeli');
        Assert::true(str_starts_with((string) $result['number'], 'ORD-'), 'Sipariş numarası ORD- önekli olmalı');

        // Sipariş satırı
        $order = $one('SELECT * FROM orders WHERE id = ?', [(int) $result['order_id']]);
        Assert::true($order !== null, 'Sipariş satırı yazılmalı');
        Assert::same('pending_payment', (string) $order['status'], 'Aşama 4: sipariş pending_payment doğar');
        Assert::same($result['number'], (string) $order['order_number']);
        $approx(340.00, $order['items_total'], 'Kalem toplamı (2×100 + 2×50 + 1×40)');
        $approx(34.00, $order['discount_total'], '%10 kupon indirimi (340 × 0.10)');
        $approx(49.90, $order['shipping_total'], '306.00 < 750 eşiği → kargo ücreti');
        $approx(355.90, $order['grand_total'], '340 − 34 + 49.90');
        $approx(56.67, $order['tax_total'], 'KDV dahil tutardan hesaplanan vergi (33.33+16.67+6.67)');
        Assert::same('HOSGELDIN10', (string) $order['coupon_code']);
        $billing = json_decode((string) $order['billing_snapshot'], true);
        Assert::true(is_array($billing) && ($billing['city'] ?? '') === 'İstanbul', 'Adres snapshot\'ı JSON olmalı');

        // Kalem snapshot'ları
        $items = $scalar('SELECT COUNT(*) FROM order_items WHERE order_id = ?', [(int) $result['order_id']]);
        Assert::same(3, (int) $items, '3 sipariş kalemi');
        $u1Item = $one('SELECT * FROM order_items WHERE order_id = ? AND product_id = ?', [(int) $result['order_id'], $u1]);
        Assert::true($u1Item !== null);
        Assert::same('Checkout Kalemi', (string) $u1Item['product_name_snapshot'], 'Ürün adı SNAPSHOT');
        Assert::same('CHK-U1', (string) $u1Item['sku_snapshot'], 'SKU SNAPSHOT');
        Assert::same(2, (int) $u1Item['quantity']);
        $approx(100.00, $u1Item['unit_price'], 'Birim fiyat = DB sale_price');
        $approx(200.00, $u1Item['line_total']);
        $approx(33.33, $u1Item['tax_amount'], 'line − line/(1+rate/100) = 200 − 166.67');
        Assert::same($supplierId, (int) $u1Item['supplier_id'], 'Tedarikçi snapshot\'ı psd\'den gelmeli');

        // Stok düşümü + stock_status
        $u4Stock = $one('SELECT stock, stock_status FROM products WHERE id = ?', [$u4]);
        Assert::same(0, (int) $u4Stock['stock'], 'U4 stok 1−1=0');
        Assert::same('out_of_stock', (string) $u4Stock['stock_status'], 'Stok 0 → stock_status out_of_stock');
        Assert::same(3, (int) $scalar('SELECT stock FROM products WHERE id = ?', [$u1]), 'U1 5−2=3');
        Assert::same(8, (int) $scalar('SELECT stock FROM products WHERE id = ?', [$u2]), 'U2 10−2=8');
        Assert::same('in_stock', (string) $scalar('SELECT stock_status FROM products WHERE id = ?', [$u1]));

        // Sepet kapanışı
        Assert::same('completed', (string) $scalar('SELECT status FROM carts WHERE id = ?', [$cartId]), 'Sepet completed olmalı');

        // Kupon kullanımı
        Assert::same(1, (int) $scalar('SELECT used_count FROM coupons WHERE id = ?', [$couponId]), 'used_count 0→1 artmalı');

        // Logger çıktısı (order.placed) — depolama yolu App::boot ile çözülür
        $appLog = (string) @file_get_contents(App::storagePath('logs') . '/app.log');
        Assert::true(str_contains($appLog, (string) $result['number']), 'order.placed sipariş numarasıyla loglanmalı');
    });

    $t->addTest('Checkout: stok yetersiz — ValidationException + ROLLBACK (sipariş yok, stok değişmedi)', static function () use ($cart, $checkout, $loginCustomer, $validInput, $expectErrors, $scalar, $exec, $u1): void {
        $loginCustomer('rollback');
        $svcCart = $cart();
        $svcCart->add($u1, null, 1);
        $cartId = (int) $svcCart->resolve(false)['id'];

        // Sipariş arası yarış: stok hizmet dışı yolla düşürüldü
        $exec('UPDATE products SET stock = 0 WHERE id = ?', [$u1]);
        $ordersBefore = (int) $scalar('SELECT COUNT(*) FROM orders');

        $expectErrors(static fn () => $checkout()->placeOrder($validInput(), '127.0.0.1', 'TestRunner/1.0'), ['cart'], 'Stok yetersiz');

        Assert::same($ordersBefore, (int) $scalar('SELECT COUNT(*) FROM orders'), 'Sipariş YAZILMAMALI (rollback)');
        Assert::same(0, (int) $scalar('SELECT stock FROM products WHERE id = ?', [$u1]), 'Stok düşülMEMELİ (0 kalmalı)');
        Assert::same(1, (int) $scalar('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$cartId]), 'Sepet kalemi korunmalı');
        Assert::same('active', (string) $scalar('SELECT status FROM carts WHERE id = ?', [$cartId]), 'Sepet completed OLMAMALI');

        // Sonraki testler bu aktif sepetten ve sıfırlanmış stoktan etkilenmesin
        // (uygulama davranışı doğru: rollback sepetteki kalemi ve 'active' durumu KORUR).
        $exec("UPDATE carts SET status = 'abandoned' WHERE id = ?", [$cartId]);
        $exec('UPDATE products SET stock = 5, stock_status = \'in_stock\' WHERE id = ?', [$u1]);
    });

    $t->addTest('Checkout: checkout sırasında fiyat değişirse YENİ fiyat snapshot alınır', static function () use ($cart, $checkout, $loginCustomer, $validInput, $approx, $one, $scalar, $exec, $u5): void {
        $loginCustomer('price-change');
        $svcCart = $cart();
        $svcCart->add($u5, null, 1); // 60.00 iken eklendi

        // Sepetteyken satış fiyatı değişir (sunucu DB fiyatını kullanmalı)
        $exec('UPDATE products SET sale_price = 75.55 WHERE id = ?', [$u5]);

        $result = $checkout()->placeOrder($validInput(), '127.0.0.1', 'TestRunner/1.0');

        $item = $one('SELECT * FROM order_items WHERE order_id = ? AND product_id = ?', [(int) $result['order_id'], $u5]);
        Assert::true($item !== null);
        $approx(75.55, $item['unit_price'], 'unit_price YENİ satış fiyatı olmalı (60.00 DEĞİL)');
        $approx(75.55, $item['line_total']);
        $order = $one('SELECT items_total, grand_total FROM orders WHERE id = ?', [(int) $result['order_id']]);
        $approx(75.55, $order['items_total']);
        $approx(125.45, $order['grand_total'], '75.55 + kargo 49.90 (indirimsiz)');
        Assert::same(null, $scalar('SELECT coupon_code FROM orders WHERE id = ?', [(int) $result['order_id']]));
    });

    $t->addTest('Checkout: doğrulama hataları — yanlış address_id, terms onaysız, boş sepet', static function () use ($cart, $checkout, $loginCustomer, $resetSession, $token, $validInput, $expectErrors, $scalar, $u1, $addressId): void {
        // (a) address_mode=existing + SAHİP OLUNMAYAN address_id
        $loginCustomer('bad-address');
        $svcCart = $cart();
        $svcCart->add($u1, null, 1);
        $expectErrors(static fn () => $checkout()->placeOrder($validInput([
            'address_mode' => 'existing',
            'address_id'   => 999999,
        ]), '127.0.0.1', 'TestRunner/1.0'), ['address'], 'Yabancı address_id reddedilmeli');

        // Doğru adresle existing modu çalışır (aynı sepette devam)
        $result = $checkout()->placeOrder($validInput([
            'address_mode' => 'existing',
            'address_id'   => $addressId,
        ]), '127.0.0.1', 'TestRunner/1.0');
        Assert::true((int) $result['order_id'] > 0, 'Geçerli address_id ile sipariş oluşmalı');
        $billing = json_decode((string) $scalar('SELECT billing_snapshot FROM orders WHERE id = ?', [(int) $result['order_id']]), true);
        Assert::same('Beşiktaş', (string) ($billing['district'] ?? ''), 'Kayıtlı adres snapshot\'ı kullanılmalı');

        // (b) terms onaysız
        $loginCustomer('no-terms');
        $svcCart2 = $cart();
        $svcCart2->add($u1, null, 1);
        $errors = $expectErrors(static fn () => $checkout()->placeOrder($validInput(['terms' => '0']), '127.0.0.1', 'TestRunner/1.0'), ['terms'], 'Terms onayı zorunlu');
        Assert::count(1, $errors, 'Tam adres + terms eksik → yalnız terms hatası');

        // (c) boş sepet
        $resetSession();
        Session::set('customer_id', 999999); // aktif sepeti olmayan müşteri (üye sepeti müşteriye göre çözümlenir)
        Session::set('cart_token', $token('empty-cart'));
        $expectErrors(static fn () => $checkout()->placeOrder($validInput(), '127.0.0.1', 'TestRunner/1.0'), ['cart'], 'Boş sepet reddedilmeli');
    });

    $t->addTest('Checkout: sepetteki ürün bayatlaşırsa sipariş REDDEDİLİR (rollback)', static function () use ($cart, $checkout, $loginCustomer, $validInput, $expectErrors, $scalar, $exec, $u6): void {
        $loginCustomer('stale-in-cart');
        $svcCart = $cart();
        $svcCart->add($u6, null, 1); // psd taze iken eklendi

        // Sepetteyken tedarikçi verisi bayatlar (72 saat önce)
        $exec('UPDATE product_supplier_data SET last_seen_at = DATE_SUB(NOW(), INTERVAL 72 HOUR) WHERE product_id = ?', [$u6]);

        $ordersBefore = (int) $scalar('SELECT COUNT(*) FROM orders');
        $errors = $expectErrors(static fn () => $checkout()->placeOrder($validInput(), '127.0.0.1', 'TestRunner/1.0'), ['cart'], 'Bayat ürün siparişi reddedilmeli');
        Assert::true(str_contains((string) ($errors['cart'][0] ?? ''), 'güncel değil'), 'Bayatlık mesajı dönmeli');

        Assert::same($ordersBefore, (int) $scalar('SELECT COUNT(*) FROM orders'), 'Sipariş YAZILMAMALI');
        Assert::same(5, (int) $scalar('SELECT stock FROM products WHERE id = ?', [$u6]), 'U6 stoğu değişmemeli');
    });

    $t->addTest('Checkout: test DB temizlenir, config ve oturum geri yüklenir', static function () use ($probe, $testDb, $originalDb, $resetSession, $settings): void {
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
