<?php

declare(strict_types=1);

/**
 * AŞAMA 5 — Tedarikçi siparişleri, kargo ve bildirim testleri.
 *
 * KENDİ test veritabanında (xml_ecommerce_test5_supplier) sıfırdan migration +
 * kurulum ile çalışır. SAHTE tedarikçi transport'u ile deterministik senaryolar:
 *  - Siparişin birden fazla tedarikçiye bölünmesi (kalemler + alış maliyeti + snapshot)
 *  - Ödemesi doğrulanmamış siparişin tedarikçiye GÖNDERİLME-MESİ
 *  - Manuel tedarikçi: otomatik gönderim yok → panel görevi → manuel işaretleme
 *  - API başarı / hata / zaman aşımı (unknown) — tekrarlı gönderim engelleri
 *  - Zaman aşımı sonrası ASLA otomatik resend; önce durum sorgusu (query)
 *  - Çökmüş 'sending' kaydının kurtarılması (sorgu yolu)
 *  - Manuel kargo girişi + sipariş durum eşitlemesi (shipped/delivered)
 *  - Bildirim tekrarsızlığı (dedup) + gönderim hatası kuyruğu
 *
 * Tam yol: tests/Phase5SupplierShippingTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Database\MigrationRunner;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ShipmentRepository;
use App\Repositories\SupplierRepository;
use App\Services\Order\OrderService;
use App\Services\Order\OrderStatus;
use App\Services\Payment\CheckoutFormService;
use App\Services\Payment\IyzipayMockClient;
use App\Services\Payment\PaymentService;
use App\Services\Security\Cipher;
use App\Services\Shipping\ShipmentService;
use App\Services\Shipping\ShippingAdapterFactory;
use App\Services\Store\CartService;
use App\Services\Store\CheckoutService;
use App\Services\Supplier\ApiSupplierAdapter;
use App\Services\Supplier\ManualSupplierAdapter;
use App\Services\Supplier\SupplierAdapterFactory;
use App\Services\Supplier\SupplierOrderService;
use App\Services\Supplier\SupplierOrderStatus;
use App\Services\Supplier\SupplierTransport;
use App\Services\Supplier\SupplierTransportTimeoutException;
use App\Services\Notification\NotificationService;

/** Test transportu: senaryolar arasında sıfırlanabilir sahte tedarikçi API'si. */
final class FakeSupplierTransport5 implements SupplierTransport
{
    /** @var list<array{method:string,url:string,body:string,idempotency:?string}> */
    public array $calls = [];

    public ?array $next = null;      // ['status' => int, 'body' => string]
    public ?string $timeout = null;  // non-null → istisna fırlat

    public function reset(): void
    {
        $this->calls = [];
        $this->next = null;
        $this->timeout = null;
    }

    public function request(string $method, string $url, array $headers, string $body, float $timeoutSec): array
    {
        $idem = null;
        foreach ($headers as $h) {
            if (stripos((string) $h, 'idempotency-key:') === 0) {
                $idem = trim(substr((string) $h, strlen('Idempotency-Key:')));
            }
        }
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'idempotency' => $idem];

        if ($this->timeout !== null) {
            throw new SupplierTransportTimeoutException('sahte zaman aşımı');
        }
        return $this->next ?? ['status' => 500, 'body' => '{"message":"no scenario"}'];
    }
}

return static function (TestRunner $t): void {
    $host       = (string) Config::get('database.host', '127.0.0.1');
    $port       = (int) Config::get('database.port', 3306);
    $charset    = (string) Config::get('database.charset', 'utf8mb4');
    $username   = (string) Config::get('database.username', 'root');
    $password   = (string) Config::get('database.password', '');
    $originalDb = (string) (env('DB_DATABASE') ?: 'xml_ecommerce');
    $testDb     = $originalDb . '_test5_supplier';

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
        $t->addTest('AŞAMA 5: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
    };

    $useDb = static function () use ($testDb): void {
        Config::set('database.database', $testDb);
        Database::disconnect();
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

    $scalar = static function (string $sql, array $params = []) use ($useDb): mixed {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    };

    $exec = static function (string $sql, array $params = []) use ($useDb): void {
        $useDb();
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
    };

    $token = static fn (string $name): string => hash('sha256', 'phase5-test::' . $name);

    $resetSession = static function (): void {
        Session::remove('customer_id');
        Session::remove('customer_email');
        Session::remove('customer_name');
        Session::remove('cart_token');
    };

    $validInput = static fn (array $overrides = []): array => [
        'address_mode' => 'new',
        'full_name'    => 'A5 Alıcı',
        'city'         => 'İzmir',
        'district'     => 'Karşıyaka',
        'postal_code'  => '35500',
        'line1'        => 'Test Cad. 5. Sk. No:5',
        'phone'        => '+90 555 555 55 55',
        'terms'        => '1',
        ...$overrides,
    ];

    /* ---------- Kurulum ---------- */
    /** @var array<string,int>|null $state */
    $state = null;
    $fakeTransport = new FakeSupplierTransport5();

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

        // Tedarikçi A: MANUEL (API yok)
        $stmt = $pdo->prepare('INSERT INTO suppliers (name, code, is_active, integration_type, created_at, updated_at) VALUES ("A5 Manuel Tedarikçi", "SUP5-MANUAL", 1, "manual", NOW(), NOW())');
        $stmt->execute();
        $supplierManual = (int) $pdo->lastInsertId();

        // Tedarikçi B: API (gerçek uç + şifreli kimlik bilgisi)
        $stmt = $pdo->prepare('INSERT INTO suppliers (name, code, is_active, integration_type, api_endpoint, api_auth, api_credentials_enc, created_at, updated_at) VALUES ("A5 API Tedarikçi", "SUP5-API", 1, "api", "https://api.supplier5.test/v1/orders", "bearer", ?, NOW(), NOW())');
        $stmt->execute([
            Cipher::encrypt(json_encode(['api_key' => 'TESTKEY5', 'api_secret' => 'TESTSECRET5'], JSON_UNESCAPED_UNICODE)),
        ]);
        $supplierApi = (int) $pdo->lastInsertId();

        // Müşteri
        $stmt = $pdo->prepare('INSERT INTO customers (first_name, last_name, email, password_hash, status, created_at, updated_at) VALUES ("A5", "Müşterisi", "a5@example.test", NULL, "active", NOW(), NOW())');
        $stmt->execute();
        $customerId = (int) $pdo->lastInsertId();

        $insertProduct = static function (array $row) use ($pdo): int {
            $stmt = $pdo->prepare(
                'INSERT INTO products
                    (sku, slug, name, currency, sale_price, tax_rate, stock, stock_status, status, source_type, created_at, updated_at)
                 VALUES (?, ?, ?, "TRY", ?, 20.00, ?, "in_stock", "active", "manual", NOW(), NOW())'
            );
            $stmt->execute([$row['sku'], $row['slug'], $row['name'], $row['sale_price'], $row['stock']]);
            return (int) $pdo->lastInsertId();
        };
        $insertPsd = static function (int $productId, int $supplierId, string $sku, float $price) use ($pdo): void {
            $stmt = $pdo->prepare(
                'INSERT INTO product_supplier_data
                    (product_id, supplier_id, supplier_sku, supplier_price, supplier_currency, stock, last_seen_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, "TRY", 50, NOW(), NOW(), NOW())'
            );
            $stmt->execute([$productId, $supplierId, $sku, $price]);
        };

        // p1 → MANUEL tedarikçi; p2, p3 → API tedarikçisi (satış ≠ alış fiyatları)
        $p1 = $insertProduct(['sku' => 'A5-P1', 'slug' => 'a5-p1', 'name' => 'A5 Ürün Bir', 'stock' => 1000, 'sale_price' => 100.00]);
        $p2 = $insertProduct(['sku' => 'A5-P2', 'slug' => 'a5-p2', 'name' => 'A5 Ürün İki', 'stock' => 1000, 'sale_price' => 80.00]);
        $p3 = $insertProduct(['sku' => 'A5-P3', 'slug' => 'a5-p3', 'name' => 'A5 Ürün Üç', 'stock' => 1000, 'sale_price' => 60.00]);
        $insertPsd($p1, $supplierManual, 'MAN-SKU-1', 40.0000);
        $insertPsd($p2, $supplierApi, 'API-SKU-2', 25.0000);
        $insertPsd($p3, $supplierApi, 'API-SKU-3', 55.5000);

        $state = ['supplierManual' => $supplierManual, 'supplierApi' => $supplierApi, 'customerId' => $customerId, 'p1' => $p1, 'p2' => $p2, 'p3' => $p3];
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
    $supplierManual  = (int) $state['supplierManual'];
    $supplierApi     = (int) $state['supplierApi'];
    $p1 = (int) $state['p1'];
    $p2 = (int) $state['p2'];
    $p3 = (int) $state['p3'];

    $login = static function (int $id, string $name) use ($resetSession, $token, $useDb): void {
        $useDb();
        $resetSession();
        Session::set('customer_id', $id);
        Session::set('cart_token', $token($name));
    };

    $placeOrder = static function (string $sessName, array $items) use ($login, $customerId, $validInput): array {
        $login($customerId, $sessName);
        $cart = new CartService();
        foreach ($items as [$pid, $qty]) {
            $cart->add($pid, null, $qty);
        }
        $result = (new CheckoutService())->placeOrder($validInput(), '127.0.0.1', 'Phase5Test/1.0');
        return [(int) $result['order_id'], (string) $result['number']];
    };

    /** Ödemeyi mock iyzico ile başarıya bağlar (markPaid + tedarikçi siparişi oluşumu). */
    $paySuccess = static function (int $orderId) use ($one): void {
        $order = $one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        $items = (new OrderRepository())->itemsForOrder($orderId);
        $mock  = new IyzipayMockClient();
        $init  = (new CheckoutFormService($mock))->initializeForOrder($order, $items);
        (new PaymentService($mock))->processToken((string) $init['payment']['token'], '127.0.0.1', 'phase5-setup');
    };

    $soIdFor = static function (int $orderId, int $supplierId) use ($scalar): ?int {
        $v = $scalar('SELECT id FROM supplier_orders WHERE order_id = ? AND supplier_id = ?', [$orderId, $supplierId]);
        return $v === null ? null : (int) $v;
    };

    $apiService = static fn () => new SupplierOrderService(null, new SupplierAdapterFactory(new SupplierRepository(), $fakeTransport));

    /* ================= testler ================= */

    $t->addTest('AŞAMA 5 Bölünme: çok tedarikçili sipariş → tedarikçi başına ayrı supplier_order (dahili referans + alış maliyeti + teslimat snapshot)', static function () use ($placeOrder, $paySuccess, $all, $one, $soIdFor, $supplierManual, $supplierApi, $p1, $p2): void {
        [$orderId, $number] = $placeOrder('p5-split', [[$p1, 1], [$p2, 2]]);
        $paySuccess($orderId);

        $rows = $all('SELECT * FROM supplier_orders WHERE order_id = ?', [$orderId]);
        Assert::count(2, $rows, 'iki tedarikçi → iki tedarikçi siparişi');

        $manualId = $soIdFor($orderId, $supplierManual);
        $apiId    = $soIdFor($orderId, $supplierApi);
        Assert::true($manualId !== null && $apiId !== null);

        // Dahili referanslar deterministik + benzersiz + sipariş numarası taşır
        $soMan = $one('SELECT * FROM supplier_orders WHERE id = ?', [$manualId]);
        $soApi = $one('SELECT * FROM supplier_orders WHERE id = ?', [$apiId]);
        Assert::same('SORD-' . $number . '-S' . $supplierManual, (string) $soMan['internal_reference']);
        Assert::same('SORD-' . $number . '-S' . $supplierApi, (string) $soApi['internal_reference']);

        // Kalemler tedarikçilere doğru bölünmüş; tedarikçi kodu + ALIŞ maliyeti snapshot'ı
        $manItems = $all('SELECT * FROM supplier_order_items WHERE supplier_order_id = ?', [$manualId]);
        Assert::count(1, $manItems);
        Assert::same('MAN-SKU-1', (string) $manItems[0]['supplier_sku'], 'tedarikçinin KENDİ kodu öncelikli');
        Assert::true(abs((float) $manItems[0]['unit_cost'] - 40.0) < 0.0001, 'alış maliyeti psd.supplier_price (40) — satış fiyatı değil');

        $apiItems = $all('SELECT * FROM supplier_order_items WHERE supplier_order_id = ?', [$apiId]);
        Assert::count(1, $apiItems);
        Assert::same(2, (int) $apiItems[0]['quantity']);
        Assert::same('API-SKU-2', (string) $apiItems[0]['supplier_sku']);
        Assert::true(abs((float) $apiItems[0]['unit_cost'] - 25.0) < 0.0001, 'alış maliyeti 25');

        // Teslimat snapshot'ı tedarikçi siparişine kopyalanmış
        $delivery = json_decode((string) $soMan['delivery_snapshot'], true);
        Assert::true(is_array($delivery) && ($delivery['full_name'] ?? '') === 'A5 Alıcı', 'teslimat snapshot doğru');
        Assert::same('pending', (string) $soMan['status']);
        Assert::same('pending', (string) $soApi['status']);
    });

    $t->addTest('AŞAMA 5 Ödeme koruması: ödemeye bağlı olmayan siparişler tedarikçiye ASLA gönderilmez', static function () use ($placeOrder, $paySuccess, $exec, $apiService, $one, $all, $soIdFor, $supplierApi, $p2): void {
        // a) supplier_orders YALNIZ ödeme sonrası oluşur: payment_failed siparişte kayıt YOK
        [$orderIdFailed] = $placeOrder('p5-fail', [[$p2, 1]]);
        $exec('UPDATE orders SET status = "payment_failed" WHERE id = ?', [$orderIdFailed]);
        Assert::count(0, $all('SELECT * FROM supplier_orders WHERE order_id = ?', [$orderIdFailed]), 'ödenmemiş sipariş → tedarikçi siparişi yok');

        // b) race koruması: paid siparişte kayıt oluştu, sipariş sonra refund_pending'e geçti → kuyruk işlemmez
        [$orderId] = $placeOrder('p5-guard', [[$p2, 1]]);
        $paySuccess($orderId);
        $soId = $soIdFor($orderId, $supplierApi);
        (new OrderService())->transition($orderId, OrderStatus::REFUND_PENDING, 'system');
        $result = $apiService()->processPending($soId);
        Assert::same(1, $result['skipped'], 'refund_pending siparişin kaydı işlenmez');
        Assert::same(0, (int) $one('SELECT attempts FROM supplier_orders WHERE id = ?', [$soId])['attempts'], 'deneme sayısı artmamalı');

        // c) kuyruk sorgusu paid ailesi dışındakileri görmez (repository düzeyi guard)
        $queueIds = (new \App\Repositories\SupplierOrderRepository())->pendingSendIds(200);
        Assert::false(in_array($soId, $queueIds, true), 'ödemeye bağlı olmayan kayıtlar kuyrukta listelenmez');
    });

    $t->addTest('AŞAMA 5 Manuel tedarikçi: otomatik gönderim YOK → panel görevi → manuel işaretleme → supplier_pending', static function () use ($placeOrder, $paySuccess, $apiService, $one, $all, $soIdFor, $supplierManual, $p1): void {
        [$orderId] = $placeOrder('p5-manual', [[$p1, 1]]);
        $paySuccess($orderId);
        $soId = $soIdFor($orderId, $supplierManual);

        $service = $apiService();
        $result = $service->processPending($soId);
        Assert::same(1, $result['manual'], 'manuel tedarikçiler manuel kuyrukta sayılır');
        $so = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('pending', (string) $so['status'], 'otomatik gönderim yapılmadı');
        Assert::contains('Manuel', (string) $so['error_message']);
        Assert::true($so['sent_at'] === null, 'sent_at boş');

        // Manuel işaretleme (admin)
        $service->markSentManually($soId, 'TDP-REF-1', 'e-posta ile iletildi', 999, '127.0.0.1');
        $so = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('sent', (string) $so['status']);
        Assert::same('manual', (string) $so['sent_by']);
        Assert::same('TDP-REF-1', (string) $so['external_reference']);
        Assert::true($so['sent_at'] !== null);

        // Tüm tedarikçi siparişleri iletildi → ana sipariş supplier_pending
        Assert::same('supplier_pending', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
    });

    $t->addTest('AŞAMA 5 API başarı: idempotency anahtarı gönderilir; tekrarlı processOne çift gönderim yapmaz', static function () use ($placeOrder, $paySuccess, $apiService, $fakeTransport, $one, $all, $soIdFor, $supplierApi, $p2): void {
        [$orderId] = $placeOrder('p5-api-ok', [[$p2, 2]]);
        $paySuccess($orderId);
        $soId = $soIdFor($orderId, $supplierApi);
        $so   = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);

        $fakeTransport->reset();
        $fakeTransport->next = ['status' => 200, 'body' => '{"status":"accepted","reference":"SUP-REF-42"}'];

        $result = $apiService()->processPending($soId);
        Assert::same(1, $result['sent']);
        Assert::count(1, $fakeTransport->calls, 'tek gönderim');

        $sent = $fakeTransport->calls[0];
        Assert::same('POST', $sent['method']);
        Assert::contains('"idempotency_key":"' . $so['internal_reference'] . '"', $sent['body'], 'gövdede idempotency anahtarı');
        Assert::same((string) $so['internal_reference'], (string) $sent['idempotency'], 'Idempotency-Key başlığı');
        Assert::contains('"sku":"API-SKU-2"', $sent['body']);
        Assert::contains('"quantity":2', $sent['body']);
        Assert::contains('"full_name":"A5 Alıcı"', $sent['body'], 'teslimat bilgisi payload');

        $soAfter = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('sent', (string) $soAfter['status']);
        Assert::same('SUP-REF-42', (string) $soAfter['external_reference']);
        Assert::same('api', (string) $soAfter['sent_by']);
        Assert::true($soAfter['sent_at'] !== null);
        Assert::true($soAfter['error_message'] === null);

        // Tekrarlı çağrı → skipped (status artık pending değil) → 2. gönderim/2. bildirim YOK
        $result2 = $apiService()->processPending($soId);
        Assert::same(1, $result2['skipped']);
        Assert::count(1, $fakeTransport->calls, 'ikinci gönderim YOK');
        Assert::count(1, $all('SELECT * FROM notifications WHERE event = "order.sent_to_supplier" AND ref_id = ?', [$orderId]), 'tekrarlı bildirim YOK');
    });

    $t->addTest('AŞAMA 5 API hatası: error + hata mesajı saklanır; retry → pending; yeniden gönderim çift kayıt oluşturmaz', static function () use ($placeOrder, $paySuccess, $apiService, $fakeTransport, $one, $all, $soIdFor, $supplierApi, $p3): void {
        [$orderId] = $placeOrder('p5-api-err', [[$p3, 1]]);
        $paySuccess($orderId);
        $soId = $soIdFor($orderId, $supplierApi);

        $fakeTransport->reset();
        $fakeTransport->next = ['status' => 503, 'body' => '{"message":"maintenance"}'];
        $result = $apiService()->processPending($soId);
        Assert::same(1, $result['errors']);

        $so = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('error', (string) $so['status']);
        Assert::contains('maintenance', (string) $so['error_message']);
        Assert::same(1, (int) $so['attempts']);

        // Yeniden kuyruğa al + başarılı transport
        $apiService()->retry($soId, 999, '127.0.0.1');
        Assert::same('pending', (string) $one('SELECT status FROM supplier_orders WHERE id = ?', [$soId])['status']);
        $fakeTransport->next = ['status' => 200, 'body' => '{"status":"accepted","reference":"SUP-REF-ERR-OK"}'];
        $result2 = $apiService()->processPending($soId);
        Assert::same(1, $result2['sent']);
        Assert::count(2, $fakeTransport->calls, '2 deneme = 2 HTTP çağrısı');
        Assert::count(1, $all('SELECT * FROM supplier_orders WHERE id = ?', [$soId]), 'çift kayıt yok');
        $final = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('SUP-REF-ERR-OK', (string) $final['external_reference']);
        Assert::same(2, (int) $final['attempts'], 'deneme sayısı her denemede artar');
    });

    $t->addTest('AŞAMA 5 Zaman aşımı: unknown — otomatik yeniden gönderim YOK; durum sorgusu ile çözülür', static function () use ($placeOrder, $paySuccess, $apiService, $fakeTransport, $one, $soIdFor, $supplierApi, $p2): void {
        [$orderId] = $placeOrder('p5-timeout', [[$p2, 1]]);
        $paySuccess($orderId);
        $soId = $soIdFor($orderId, $supplierApi);

        $fakeTransport->reset();
        $fakeTransport->timeout = 'x'; // zaman aşımı senaryosu
        $result = $apiService()->processPending($soId);
        Assert::same(1, $result['unknown']);

        $so = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('unknown', (string) $so['status']);
        Assert::contains('belirsiz', (string) $so['error_message']);
        Assert::same(1, (int) $so['attempts']);

        // unknown kayıtlar gönderim kuyruğuna GİRMEZ → tekrar processPending yeniden göndermez
        $fakeTransport->reset();
        $fakeTransport->next = ['status' => 200, 'body' => '{"status":"accepted"}'];
        $apiService()->processPending();
        $soCheck = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('unknown', (string) $soCheck['status'], 'unknown → otomatik yeniden gönderim YOK');
        Assert::same(1, (int) $soCheck['attempts'], 'attempts değişmedi (yeniden gönderim yapılmadı)');

        // Durum sorgusu: tedarikçide kayıt VAR ve onaylanmış
        $fakeTransport->reset();
        $fakeTransport->next = ['status' => 200, 'body' => '{"status":"confirmed"}'];
        $q = $apiService()->queryStatuses($soId);
        Assert::same(1, $q['queried']);
        Assert::same(1, $q['confirmed']);
        $soQ = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('confirmed', (string) $soQ['status']);
        Assert::true($soQ['error_message'] === null || $soQ['error_message'] === '');

        // Tüm kayıtlar iletildi → ana sipariş supplier_pending
        Assert::same('supplier_pending', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);
    });

    $t->addTest('AŞAMA 5 Çökmüş sending kurtarma: grace süresi aşılırsa sorgu yoluna alınır — yeniden gönderilmez', static function () use ($placeOrder, $paySuccess, $apiService, $fakeTransport, $exec, $one, $all, $soIdFor, $supplierApi, $p3): void {
        [$orderId] = $placeOrder('p5-crash', [[$p3, 1]]);
        $paySuccess($orderId);
        $soId = $soIdFor($orderId, $supplierApi);

        // Süreç çökmüş gibi: kaydı 'sending' durumunda bırak + updated_at'i eskilt
        $exec('UPDATE supplier_orders SET status = "sending", attempts = attempts + 1, updated_at = NOW() - INTERVAL 10 MINUTE WHERE id = ?', [$soId]);

        $ids = $all('SELECT id FROM supplier_orders WHERE status = "sending"');
        Assert::count(1, $ids);

        // processPending → sending kayıtları gönderilmez (yalnız pending işlenir)
        $fakeTransport->reset();
        $fakeTransport->next = ['status' => 200, 'body' => '{"status":"accepted","reference":"X"}'];
        $apiService()->processPending();
        Assert::count(0, $fakeTransport->calls, 'sending kaydı için HTTP çağrısı YAPILMAZ');

        // Sorgu yolu: stale sending → query → tedarikçide kayıt yok
        $fakeTransport->next = ['status' => 404, 'body' => '{"status":"not_found"}'];
        $q = $apiService()->queryStatuses();
        Assert::same(1, $q['not_found'], 'tedarikçide kayıt yok → manuel inceleme işareti');
        $so = $one('SELECT * FROM supplier_orders WHERE id = ?', [$soId]);
        Assert::same('unknown', (string) $so['status']);
        Assert::contains('Manuel inceleme', (string) $so['error_message']);
        Assert::true($so['sent_at'] === null);
    });

    $t->addTest('AŞAMA 5 Manuel kargo girişi: gönderi güncelleme → sipariş shipped/delivered eşitleme + doğrulamalar', static function () use ($placeOrder, $paySuccess, $apiService, $one, $all, $soIdFor, $supplierManual, $p1): void {
        [$orderId] = $placeOrder('p5-ship', [[$p1, 1]]);
        $paySuccess($orderId);
        $soId = $soIdFor($orderId, $supplierManual);
        $apiService()->markSentManually($soId, null, null, 999, '127.0.0.1');

        $svc = new ShipmentService();

        // Kargoya verildi — kargo bilgisi OLMADAN reddedilir
        try {
            $svc->updateForSupplierOrder($soId, ['status' => 'shipped'], 999, '127.0.0.1');
            Assert::true(false, 'takip bilgisi olmadan shipped reddedilmeliydi');
        } catch (\App\Exceptions\ValidationException $e) {
            Assert::contains('takip numarası', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        // Manuel takip numarası girişi
        $shipment = $svc->updateForSupplierOrder($soId, [
            'status' => 'shipped', 'carrier' => 'Aras Kargo', 'tracking_number' => 'ARAS-777-TR',
        ], 999, '127.0.0.1');
        Assert::same('shipped', (string) $shipment['status']);
        Assert::same('Aras Kargo', (string) $shipment['carrier']);
        Assert::true($shipment['shipped_at'] !== null);
        Assert::count(1, $all('SELECT * FROM shipments WHERE supplier_order_id = ?', [$soId]), 'tek gönderi satırı');

        // Tüm gönderiler yolda → ana sipariş shipped
        Assert::same('shipped', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);

        // Teslim
        $shipment2 = $svc->updateForSupplierOrder($soId, ['status' => 'delivered'], 999, '127.0.0.1');
        Assert::same('delivered', (string) $shipment2['status']);
        Assert::true($shipment2['delivered_at'] !== null);
        Assert::same('delivered', (string) $one('SELECT status FROM orders WHERE id = ?', [$orderId])['status']);

        // Aynı takip numarası başka gönderiye verilemez
        [$orderId2] = $placeOrder('p5-ship2', [[$p1, 1]]);
        $paySuccess($orderId2);
        $soId2 = $soIdFor($orderId2, $supplierManual);
        $apiService()->markSentManually($soId2, null, null, 999, '127.0.0.1');
        try {
            $svc->updateForSupplierOrder($soId2, [
                'status' => 'shipped', 'carrier' => 'Aras Kargo', 'tracking_number' => 'ARAS-777-TR',
            ], 999, '127.0.0.1');
            Assert::true(false, 'mükerrer takip numarası reddedilmeliydi');
        } catch (\App\Exceptions\ValidationException $e) {
            Assert::contains('başka bir gönderide', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }

        // Geçersiz durum geçişi: delivered → preparing reddedilir
        try {
            $svc->updateForSupplierOrder($soId, ['status' => 'preparing', 'carrier' => 'Aras Kargo', 'tracking_number' => 'ARAS-777-TR'], 999, '127.0.0.1');
            Assert::true(false, 'delivered→preparing reddedilmeliydi');
        } catch (\App\Exceptions\ValidationException $e) {
            Assert::contains('Geçersiz geçiş', json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
        }
    });

    $t->addTest('AŞAMA 5 İptal zinciri: ana sipariş iptali → gönderilmemiş tedarikçi siparişleri cancelled', static function () use ($placeOrder, $paySuccess, $one, $all, $soIdFor, $supplierApi, $p2): void {
        [$orderId] = $placeOrder('p5-cancel', [[$p2, 1]]);
        $paySuccess($orderId);
        $soId = $soIdFor($orderId, $supplierApi);
        Assert::same('pending', (string) $one('SELECT status FROM supplier_orders WHERE id = ?', [$soId])['status']);

        (new OrderService())->transition($orderId, OrderStatus::CANCELLED, 'admin', 999, '127.0.0.1', ['reason' => 'p5-test']);
        Assert::same('cancelled', (string) $one('SELECT status FROM supplier_orders WHERE id = ?', [$soId])['status']);
        Assert::contains('iptal', (string) $one('SELECT error_message FROM supplier_orders WHERE id = ?', [$soId])['error_message']);
        // İptal bildirimi kuyruğa yazıldı (dedup anahtarıyla)
        Assert::count(1, $all('SELECT * FROM notifications WHERE event = "order.cancelled" AND ref_id = ?', [$orderId]));
    });

    $t->addTest('AŞAMA 5 Bildirimler: dedup UNIQUE ile tekrarlı gönderim engellenir; SMTP hatası kuyrukta kalır ve sonra gönderilir', static function () use ($placeOrder, $one, $all, $exec, $p1): void {
        [$orderId] = $placeOrder('p5-notif', [[$p1, 1]]);
        $order = $one('SELECT * FROM orders WHERE id = ?', [$orderId]);

        // Aynı olay iki kez → TEK kayıt (dedup_key UNIQUE + INSERT IGNORE)
        NotificationService::paymentConfirmed($order);
        NotificationService::paymentConfirmed($order);
        NotificationService::paymentConfirmed($order);
        Assert::count(1, $all('SELECT * FROM notifications WHERE event = "payment.confirmed" AND ref_id = ?', [$orderId]), '3 çağrı → 1 bildirim');

        // Gönderim hatası: SMTP sürücüsü host'suz → hata loglanır, kayıt deneme sayısıyla kuyrukta kalır
        $oldDriver = Config::get('mail.driver');
        Config::set('mail.driver', 'smtp');
        NotificationService::orderDelivered($order);
        $row = $one('SELECT * FROM notifications WHERE event = "order.delivered" AND ref_id = ?', [$orderId]);
        Assert::true($row !== null);
        Assert::same('pending', (string) $row['status'], 'hata sonrası tekrar deneme için pending');
        Assert::same(1, (int) $row['attempts'], 'attempts artmış');
        Assert::contains('SMTP', (string) $row['last_error'], 'hata mesajı saklanmış');
        Config::set('mail.driver', $oldDriver);

        // Kuyruk boşaltma (log sürücü) → gönderilir
        $result = NotificationService::processQueue(50);
        $row2 = $one('SELECT * FROM notifications WHERE id = ?', [(int) $row['id']]);
        Assert::same('sent', (string) $row2['status']);
        Assert::true($row2['sent_at'] !== null);
    });

    $t->addTest('AŞAMA 5 Adaptör sözleşmesi: ManualSupplierAdapter asla sahte başarı üretmez; API payload no-secret', static function () use ($fakeTransport): void {
        $manual = new ManualSupplierAdapter();
        Assert::false($manual->supportsApi());
        $r = $manual->sendOrder([], [], []);
        Assert::false($r['ok'], 'manuel adaptör asla ok=true dönmez');

        // API adaptörü payload'ında kimlik bilgisi ASLA taşımaz
        $adapter = new ApiSupplierAdapter([
            'endpoint' => 'https://api.x.test/orders', 'auth' => 'bearer',
            'api_key' => 'SECRETKEY', 'api_secret' => 'SECRETSECRET',
        ], $fakeTransport);
        $fakeTransport->reset();
        $fakeTransport->next = ['status' => 200, 'body' => '{"status":"accepted"}'];
        $adapter->sendOrder(
            ['internal_reference' => 'SORD-TEST-1'],
            [['supplier_sku' => 'S1', 'quantity' => 1, 'unit_cost' => 10, 'line_total' => 10]],
            ['order_number' => 'ORD-X', 'delivery' => []]
        );
        $body = $fakeTransport->calls[0]['body'] ?? '';
        Assert::true(!str_contains($body, 'SECRETKEY') && !str_contains($body, 'SECRETSECRET'), 'payload gizli anahtar İÇERMEZ (kimlik yalnız Authorization başlığında)');
    });
};
