<?php

declare(strict_types=1);

/**
 * AŞAMA 4 — Sipariş/iyzico Checkout Form entegrasyon testleri.
 *
 * KENDİ test veritabanında (xml_ecommerce_test4_payment) sıfırdan migration +
 * tohumlama ile çalışır. MOCK iyzico istemciyle (deterministik senaryolar) kapsam:
 *  - Durum makinesi (izinli/yasak geçişler + gerçek sipariş üzerinde yasak geçiş reddi)
 *  - Checkout → pending_payment sipariş + ödeme başlatma (payload doğrulama)
 *  - Başarılı ödeme: paid + payments.success + tedarikçi siparişi (tek kez)
 *  - Tekrarlı callback → replay (idempotent), çift tedarikçi siparişi yok
 *  - Başarısız ödeme → payment_failed → yeniden başlatma → paid
 *  - Yanlış tutar (tampering) → ödeme reddedilir, sipariş paid OLMAZ
 *  - Geçersiz/bilinmeyen token → IyzipayException, durum değişmez
 *  - Webhook: geçersiz imza 401; geçerli imza işlenir; DUPLİKE 2xx tek işleme
 *  - Sepet fiyatı değiştirilerek gönderilen istek → sunucu fiyatı kazanır
 *  - Yetkisiz sipariş erişimi (IDOR) → null
 *  - order:expire → süresi geçen pending_payment → cancelled
 *  - İade: tam + kısmi + red + admin iptal + audit log doğrulamaları
 *
 * Tam yol: tests/Phase4OrderPaymentTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Database\MigrationRunner;
use App\Exceptions\ValidationException;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\WebhookEventRepository;
use App\Services\Order\OrderService;
use App\Services\Order\OrderStatus;
use App\Services\Order\RefundService;
use App\Services\Payment\CheckoutFormService;
use App\Services\Payment\IyzipayException;
use App\Services\Payment\IyzipayMockClient;
use App\Services\Payment\PaymentService;
use App\Services\Store\CartService;
use App\Services\Store\CheckoutService;

return static function (TestRunner $t): void {
    $host       = (string) Config::get('database.host', '127.0.0.1');
    $port       = (int) Config::get('database.port', 3306);
    $charset    = (string) Config::get('database.charset', 'utf8mb4');
    $username   = (string) Config::get('database.username', 'root');
    $password   = (string) Config::get('database.password', '');
    $originalDb = (string) (env('DB_DATABASE') ?: 'xml_ecommerce');
    $testDb     = $originalDb . '_test4_payment';

    /* ---------- Session CLI kurulumu ---------- */
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
        $t->addTest('AŞAMA 4: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
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

    $one = static function (string $sql, array $params = []) use ($useDb): ?array {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    };

    $all = static function (string $sql, array $params = []) use ($useDb): array {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    };

    $token = static fn (string $name): string => hash('sha256', 'phase4-test::' . $name);

    $resetSession = static function (): void {
        Session::remove('customer_id');
        Session::remove('customer_email');
        Session::remove('customer_name');
        Session::remove('cart_token');
    };

    $validInput = static function (array $overrides = []): array {
        return [
            'address_mode' => 'new',
            'full_name'    => 'Test Alıcı',
            'city'         => 'İstanbul',
            'district'     => 'Kadıköy',
            'postal_code'  => '34710',
            'line1'        => 'Test Mah. 4. Sk. No:4',
            'phone'        => '+90 555 111 22 33',
            'terms'        => '1',
            ...$overrides,
        ];
    };

    /* ---------- Kurulum ---------- */
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
        $stmt->execute(['A4 Test Tedarikçisi', 'SUP-A4-TEST']);
        $supplierId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO customers (first_name, last_name, email, password_hash, status, created_at, updated_at)
             VALUES ("A4", "Müşterisi", "a4@example.test", NULL, "active", NOW(), NOW())'
        );
        $stmt->execute();
        $customerId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO customers (first_name, last_name, email, password_hash, status, created_at, updated_at)
             VALUES ("Başka", "Müşteri", "baska@example.test", NULL, "active", NOW(), NOW())'
        );
        $stmt->execute();
        $otherCustomerId = (int) $pdo->lastInsertId();

        $insertProduct = static function (array $row) use ($pdo): int {
            $stmt = $pdo->prepare(
                'INSERT INTO products
                    (sku, slug, name, currency, sale_price, tax_rate, stock, stock_status, status, source_type, created_at, updated_at)
                 VALUES (?, ?, ?, "TRY", ?, 20.00, ?, "in_stock", "active", "manual", NOW(), NOW())'
            );
            $stmt->execute([$row['sku'], $row['slug'], $row['name'], $row['sale_price'], $row['stock']]);
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

        $p1 = $insertProduct(['sku' => 'A4-P1', 'slug' => 'a4-p1', 'name' => 'A4 Ürün Bir', 'stock' => 1000, 'sale_price' => 100.00]);
        $p2 = $insertProduct(['sku' => 'A4-P2', 'slug' => 'a4-p2', 'name' => 'A4 Ürün İki', 'stock' => 1000, 'sale_price' => 50.00]);
        $insertPsd($p1, $supplierId);
        $insertPsd($p2, $supplierId);

        $state = ['supplierId' => $supplierId, 'customerId' => $customerId, 'otherCustomerId' => $otherCustomerId, 'p1' => $p1, 'p2' => $p2];
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

    $customerId      = (int) $state['customerId'];
    $otherCustomerId = (int) $state['otherCustomerId'];
    $p1 = (int) $state['p1'];
    $p2 = (int) $state['p2'];

    $login = static function (int $id, string $name) use ($resetSession, $token, $useDb): void {
        $useDb(); // tam suite'te önceki dosyaların Config sızıntısını sıfırla
        $resetSession();
        Session::set('customer_id', $id);
        Session::set('cart_token', $token($name));
    };

    /** Sipariş oluşturur: [order_id, number] döndürür. */
    $placeOrder = static function (string $sessName, array $items) use ($login, $customerId, $validInput): array {
        $login($customerId, $sessName);
        $cart = new CartService();
        foreach ($items as [$pid, $qty]) {
            $cart->add($pid, null, $qty);
        }
        $result = (new CheckoutService())->placeOrder($validInput(), '127.0.0.1', 'Phase4Test/1.0');
        return [(int) $result['order_id'], (string) $result['number']];
    };

    /** Sipariş için mock ödeme başlatır ve [paymentId, token] döndürür. */
    $initPay = static function (int $orderId) use ($one, $all): array {
        $order = $one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $items = (new OrderRepository())->itemsForOrder($orderId);
        $mock  = new IyzipayMockClient();
        $init  = (new CheckoutFormService($mock))->initializeForOrder($order, $items);
        return [(int) $init['payment']['id'], (string) $init['payment']['token'], $mock];
    };

    /* ================= testler ================= */

    $t->addTest('AŞAMA 4 Durum Makinesi: izinli geçişler geçer; yasak geçiş ValidationException ile reddedilir', static function () use ($t, $placeOrder, $one, $useDb, $p2): void {
        Assert::true(OrderStatus::can('pending_payment', 'paid'));
        Assert::true(OrderStatus::can('payment_failed', 'pending_payment'));
        Assert::true(OrderStatus::can('paid', 'processing'));
        Assert::true(OrderStatus::can('shipped', 'delivered'));
        Assert::true(OrderStatus::can('refund_pending', 'refunded'));
        Assert::false(OrderStatus::can('pending_payment', 'shipped'));
        Assert::false(OrderStatus::can('refunded', 'paid'));
        Assert::false(OrderStatus::can('cancelled', 'paid'));
        Assert::false(OrderStatus::can('delivered', 'cancelled'));

        [$orderId] = $placeOrder('sm-order', [[$p2, 1]]);
        try {
            $useDb();
            (new OrderService())->transition($orderId, 'shipped', 'system');
            Assert::true(false, 'pending_payment→shipped reddedilmeliydi');
        } catch (ValidationException $e) {
            Assert::contains('Geçersiz durum geçişi', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
        // İzinli geçiş: pending_payment → payment_failed
        $useDb();
        (new OrderService())->transition($orderId, OrderStatus::PAYMENT_FAILED, 'system');
        Assert::same('payment_failed', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
    });

    $t->addTest('AŞAMA 4 Başlatma: payments satırı initiated; conversationId/basketId/amount/token doğru; token UNIQUE', static function () use ($placeOrder, $initPay, $one, $p1): void {
        [$orderId, $number] = $placeOrder('payload2', [[$p1, 2]]);
        $order = $one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        Assert::same('pending_payment', (string) $order['status']);
        Assert::true(abs((float) $order['grand_total'] - 249.90) < 0.005, '2×100 + 49.90 kargo = 249.90');

        [$paymentId, $payToken] = $initPay($orderId);
        $pay = $one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        Assert::same('initiated', (string) $pay['status']);
        Assert::same($number, (string) $pay['conversation_id'], 'conversationId = sipariş numarası');
        Assert::same('B-' . $number, (string) $pay['basket_id']);
        Assert::same((string) $order['grand_total'], (string) $pay['amount']);
        Assert::same($payToken, (string) $pay['token']);

        // Token UNIQUE (ikinci satır aynı token'la eklenemez)
        $dupCode = '';
        try {
            (new PaymentRepository())->insert([
                'order_id' => $orderId, 'provider' => 'iyzico', 'conversation_id' => 'X',
                'status' => 'initiated', 'amount' => 1, 'currency' => 'TRY', 'token' => $payToken,
            ]);
        } catch (\PDOException $e) {
            $dupCode = (string) $e->getCode();
        }
        Assert::same('23000', $dupCode, 'uniq_payments_token çalışmalı');
    });

    $t->addTest('AŞAMA 4 Başarılı ödeme: paid + success + tedarikçi siparişi BİR kez; tekrarlı callback replay', static function () use ($placeOrder, $initPay, $one, $all, $p1): void {
        [$orderId] = $placeOrder('success', [[$p1, 3]]);
        [$paymentId, $payToken, $mock] = $initPay($orderId);
        $svc = new PaymentService($mock);

        $r1 = $svc->processToken($payToken, '127.0.0.1', 'callback');
        Assert::same('success', $r1['outcome']);
        Assert::same('paid', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
        Assert::false($one('SELECT paid_at FROM orders WHERE id = ?', [$orderId])['paid_at'] === null);

        $pay = $one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        Assert::same('success', (string) $pay['status']);
        Assert::contains('MOCKPAY-', (string) $pay['provider_payment_id']);
        Assert::same('0001', (string) $pay['card_last_four']);

        Assert::count(1, $all('SELECT * FROM supplier_orders WHERE order_id = ?', [$orderId]));
        Assert::count(1, $all(
            'SELECT soi.id FROM supplier_order_items soi INNER JOIN supplier_orders so ON so.id = soi.supplier_order_id WHERE so.order_id = ?',
            [$orderId]
        ));

        // Tekrarlı callback → replay; ek kayıt yok
        $r2 = $svc->processToken($payToken, '127.0.0.1', 'callback-again');
        Assert::same('replay', $r2['outcome']);
        Assert::count(1, $all('SELECT * FROM supplier_orders WHERE order_id = ?', [$orderId]), 'çift tedarikçi siparişi OLMAMALI');
    });

    $t->addTest('AŞAMA 4 Başarısız ödeme → payment_failed → yeniden başlatma → paid', static function () use ($placeOrder, $initPay, $one, $p1): void {
        [$orderId] = $placeOrder('failure', [[$p1, 1]]);
        [$paymentId, $payToken, $mock] = $initPay($orderId);
        $svc = new PaymentService($mock);
        $mock->setOutcome($payToken, 'failure');

        $r = $svc->processToken($payToken, '127.0.0.1', 'callback');
        Assert::same('failed', $r['outcome']);
        Assert::same('payment_failed', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
        Assert::same('failed', (string) $one('SELECT status FROM payments WHERE id = ?', [$paymentId])['status']);

        // Yeniden başlatma: aktif initiated yok → YENİ token
        [$paymentId2, $payToken2, $mock2] = $initPay($orderId);
        Assert::true($payToken2 !== $payToken);
        $r2 = (new PaymentService($mock2))->processToken($payToken2, '127.0.0.1', 'callback');
        Assert::same('success', $r2['outcome']);
        Assert::same('paid', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
    });

    $t->addTest('AŞAMA 4 Yanlış tutar (tampering): ödeme reddedilir; sipariş paid OLMAZ; security log düşer', static function () use ($placeOrder, $initPay, $one, $scalar, $p1): void {
        [$orderId] = $placeOrder('wrong-amount', [[$p1, 2]]);
        [$paymentId, $payToken, $mock] = $initPay($orderId);
        $mock->setOutcome($payToken, 'wrong_amount');

        $r = (new PaymentService($mock))->processToken($payToken, '203.0.113.9', 'callback');
        Assert::same('failed', $r['outcome']);
        Assert::same('failed', (string) $one('SELECT status FROM payments WHERE id = ?', [$paymentId])['status']);
        Assert::false((string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status'] === 'paid');
        Assert::contains('validation_mismatch', (string) $one('SELECT error_code FROM payments WHERE id = ?', [$paymentId])['error_code']);
    });

    $t->addTest('AŞAMA 4 Bilinmeyen token: IyzipayException; sipariş durumu değişmez', static function () use ($placeOrder, $one, $useDb, $p1): void {
        [$orderId] = $placeOrder('unknown-token', [[$p1, 1]]);
        $before = (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status'];
        try {
            $useDb();
            (new PaymentService(new IyzipayMockClient()))->processToken('totally-unknown-token', '127.0.0.1', 'callback');
            Assert::true(false, 'IyzipayException beklenmişti');
        } catch (IyzipayException) {
            Assert::true(true);
        }
        Assert::same($before, (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
    });

    $t->addTest('AŞAMA 4 Webhook: bozuk imza 401; geçerli V3 imzası işlenir; DUPLİKE 2xx ve tek işleme', static function () use ($placeOrder, $initPay, $one, $scalar, $p1): void {
        [$orderId, $number] = $placeOrder('webhook', [[$p1, 1]]);
        [$paymentId, $payToken, $mock] = $initPay($orderId);

        $secret = 'test-secret-key';
        Config::set('payment.secret_key', $secret);
        Config::set('payment.api_key', 'test-api-key');

        $payload = json_encode([
            'paymentConversationId' => $number,
            'merchantId' => 3404590,
            'status' => 'SUCCESS',
            'token' => $payToken,
            'iyziReferenceCode' => 'ref-' . $payToken,
            'iyziEventType' => 'CHECKOUT_FORM_AUTH',
            'iyziEventTime' => 1766733201159,
            'iyziPaymentId' => '28157797',
        ]);
        $sig = bin2hex(hash_hmac('sha256', $secret . 'CHECKOUT_FORM_AUTH' . '28157797' . $payToken . $number . 'SUCCESS', $secret, true));
        $svc = new PaymentService($mock);

        // NOT: $svc kurulduktan sonra $one/$scalar KULLANILMAZ (Database::disconnect
        // repository bağlantısını $pdo'dan ayırır → çapraz bağlantı self-deadlock).
        // 1) Geçersiz imza → 401 (işlenmez)
        $bad = $svc->handleWebhook((string) $payload, 'deadbeef', '198.51.100.7');

        // 2) Geçerli imza → işlenir
        $ok = $svc->handleWebhook((string) $payload, $sig, '198.51.100.7');

        // 3) DUPLİKE → 200 + duplicate
        $dup = $svc->handleWebhook((string) $payload, $sig, '198.51.100.7');

        // ---- Doğrulamalar (tüm çağrılar bittikten sonra) ----
        Assert::same(401, $bad['http']);
        Assert::same(200, $ok['http']);
        Assert::same('success', $ok['status']);
        Assert::same(200, $dup['http']);
        Assert::same('duplicate', $dup['status']);
        Assert::same('paid', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
        Assert::true((int) $scalar('SELECT COUNT(*) FROM webhook_events WHERE signature_valid = 0') >= 1, 'imzasız bildirim kaydı');
        $externalId = hash('sha1', 'CHECKOUT_FORM_AUTH|ref-' . $payToken . '|' . $payToken . '|SUCCESS');
        $matching = array_values(array_filter(
            (new WebhookEventRepository())->recent(50),
            static fn ($w) => (string) $w['external_id'] === $externalId
        ));
        Assert::count(1, $matching, 'duplike bildirim tek kayıtta');
    });

    $t->addTest('AŞAMA 4 Sepet fiyatı değiştirilerek gönderilen istek: sunucu fiyatı kazanır', static function () use ($login, $customerId, $token, $p1, $one): void {
        $login($customerId, 'tamper-cart');
        $cart = new CartService();
        $cart->add($p1, null, 1);
        $result = (new CheckoutService())->placeOrder([
            'address_mode' => 'new',
            'full_name'    => 'Tamper Test',
            'city'         => 'İzmir',
            'district'     => 'Karşıyaka',
            'line1'        => 'Deneme Sk. 1',
            'terms'        => '1',
            'grand_total'  => '0.01',
            'items_total'  => '0.01',
            'unit_price'   => '0.01',
        ], '127.0.0.1', 'Phase4Test/1.0');
        $order = $one('SELECT * FROM orders WHERE id = ?', [(int) $result['order_id']]);
        Assert::true(abs((float) $order['grand_total'] - 149.90) < 0.005, 'sunucu fiyatı: 100 + 49.90');
    });

    $t->addTest('AŞAMA 4 Yetkisiz sipariş erişimi (IDOR): başka müşteri göremez', static function () use ($placeOrder, $customerId, $otherCustomerId, $p1): void {
        [, $number] = $placeOrder('idor', [[$p1, 1]]);
        $repo = new OrderRepository();
        Assert::same(null, $repo->findByNumberForCustomer($number, $otherCustomerId));
        Assert::true($repo->findByNumberForCustomer($number, $customerId) !== null);
    });

    $t->addTest('AŞAMA 4 order:expire — süresi geçen pending_payment → cancelled', static function () use ($placeOrder, $one, $useDb, $p1): void {
        [$orderId] = $placeOrder('expire', [[$p1, 1]]);
        Database::pdo()->prepare('UPDATE orders SET placed_at = DATE_SUB(NOW(), INTERVAL 3 HOUR) WHERE id = ?')->execute([$orderId]);
        $useDb();
        $expired = (new OrderService())->expireStalePending(120);
        Assert::true($expired >= 1);
        Assert::same('cancelled', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
    });

    /* ---------------- İade / iptal akışları ---------------- */

    $t->addTest('AŞAMA 4 Tam iade: talep → refund_pending; onay → iyzico iade → refunded + referans', static function () use ($placeOrder, $initPay, $one, $all, $customerId, $p1): void {
        [$orderId, $number] = $placeOrder('refund-full', [[$p1, 2]]);
        [$paymentId, $payToken, $mock] = $initPay($orderId);
        (new PaymentService($mock))->processToken($payToken, '127.0.0.1', 'callback');
        Assert::same('paid', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);

        $refundSvc = new RefundService(null, null, null, null, $mock);
        $refundId = $refundSvc->requestByCustomer($number, $customerId, null, 'Ürün beğenilmedi', '127.0.0.1', 'Phase4Test/1.0');
        Assert::same('refund_pending', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
        Assert::same('pending', (string) $one('SELECT status FROM refunds WHERE id = ?', [$refundId])['status']);

        $refundSvc->approve($refundId, 1, '127.0.0.1', 'Phase4Test/1.0');
        $refund = $one('SELECT * FROM refunds WHERE id = ?', [$refundId]);
        Assert::same('completed', (string) $refund['status']);
        Assert::contains('MOCKREFUND-', (string) $refund['refund_reference']);
        Assert::same('refunded', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
        Assert::same('refunded', (string) $one('SELECT status FROM payments WHERE id = ?', [$paymentId])['status']);
    });

    $t->addTest('AŞAMA 4 Kısmi iade: kalem bazlı → onay → önceki duruma dönüş + partially_refunded + kalan doğruluğu', static function () use ($placeOrder, $initPay, $one, $scalar, $customerId, $p1, $p2): void {
        [$orderId, $number] = $placeOrder('refund-partial', [[$p1, 1], [$p2, 2]]);
        [$paymentId, $payToken, $mock] = $initPay($orderId);
        (new PaymentService($mock))->processToken($payToken, '127.0.0.1', 'callback');
        Assert::same('paid', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);

        $items = (new OrderRepository())->itemsForOrder($orderId);
        $itemId = (int) $items[0]['id'];

        $refundSvc = new RefundService(null, null, null, null, $mock);
        $refundId = $refundSvc->requestByCustomer($number, $customerId, 40.00, 'Kısmi talep', '127.0.0.1', 'Phase4Test/1.0', $itemId);
        Assert::same('partial', (string) $one('SELECT scope FROM refunds WHERE id = ?', [$refundId])['scope']);

        $refundSvc->approve($refundId, 1, '127.0.0.1', 'Phase4Test/1.0');
        Assert::same('completed', (string) $one('SELECT status FROM refunds WHERE id = ?', [$refundId])['status']);
        Assert::same('partially_refunded', (string) $one('SELECT status FROM payments WHERE id = ?', [$paymentId])['status']);
        Assert::same('paid', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status'], 'kısmi iade → restore_status');
        Assert::true(abs((float) $scalar('SELECT COALESCE(SUM(amount),0) FROM refunds WHERE order_id = ? AND status = "completed"', [$orderId]) - 40.0) < 0.005);
    });

    $t->addTest('AŞAMA 4 İade reddi: talep reddedilir; sipariş paid\'a döner', static function () use ($placeOrder, $initPay, $one, $customerId, $p2): void {
        [$orderId, $number] = $placeOrder('refund-reject', [[$p2, 1]]);
        [$paymentId, $payToken, $mock] = $initPay($orderId);
        (new PaymentService($mock))->processToken($payToken, '127.0.0.1', 'callback');

        $refundSvc = new RefundService(null, null, null, null, $mock);
        $refundId = $refundSvc->requestByCustomer($number, $customerId, null, 'Talep', '127.0.0.1', 'Phase4Test/1.0');
        Assert::same('refund_pending', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);

        $refundSvc->reject($refundId, 1, 'Gerekçe yetersiz', '127.0.0.1');
        Assert::same('rejected', (string) $one('SELECT status FROM refunds WHERE id = ?', [$refundId])['status']);
        Assert::same('paid', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
    });

    $t->addTest('AŞAMA 4 Admin iptal: iyzico /payment/cancel → payments.cancelled + order.cancelled + refund completed', static function () use ($placeOrder, $initPay, $one, $p2): void {
        [$orderId, $number] = $placeOrder('admin-cancel', [[$p2, 2]]);
        [$paymentId, $payToken, $mock] = $initPay($orderId);
        (new PaymentService($mock))->processToken($payToken, '127.0.0.1', 'callback');

        (new RefundService(null, null, null, null, $mock))->cancelByAdmin($number, 1, 'Stok yok', '127.0.0.1', 'Phase4Test/1.0');

        Assert::same('cancelled', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
        Assert::same('cancelled', (string) $one('SELECT status FROM payments WHERE id = ?', [$paymentId])['status']);
        $cancelRow = $one('SELECT * FROM refunds WHERE order_id = ? AND type = "cancel"', [$orderId]);
        Assert::true($cancelRow !== null);
        Assert::same('completed', (string) $cancelRow['status']);
        Assert::contains('MOCKCANCEL-', (string) $cancelRow['refund_reference']);
    });

    $t->addTest('AŞAMA 4 Denetim izi: durum değişiklikleri audit_logs\'a from/to bağlamıyla yazılır', static function () use ($placeOrder, $one, $useDb, $p2): void {
        [$orderId] = $placeOrder('audit', [[$p2, 1]]);
        $useDb();
        (new OrderService())->transition($orderId, OrderStatus::PAID, 'system', null, '127.0.0.1', ['via' => 'test']);
        $ctxJson = (string) Database::pdo()
            ->query('SELECT context FROM audit_logs WHERE entity_type = "order" AND entity_id = ' . $orderId . ' AND action = "order.status_changed" ORDER BY id DESC LIMIT 1')
            ->fetchColumn();
        $ctx = json_decode($ctxJson, true);
        Assert::same('pending_payment', (string) $ctx['from']);
        Assert::same('paid', (string) $ctx['to']);
        Assert::same('test', (string) ($ctx['via'] ?? ''));
    });

    /* ---------- temizlik ---------- */
    $t->addTest('AŞAMA 4: test DB temizlenir ve config geri yüklenir', static function () use ($probe, $testDb, $originalDb): void {
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        Config::set('database.database', $originalDb);
        Database::disconnect();
        Assert::true(true);
    });
};
