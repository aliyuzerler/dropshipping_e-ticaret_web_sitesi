<?php

declare(strict_types=1);

/**
 * Aşama 3 — Müşteri üyelik servis testleri (CustomerAuthService).
 *
 * KENDİ test veritabanında (xml_ecommerce_test3_auth) sıfırdan migration ile
 * çalışır. Kapsam: kayıt (e-posta normalizasyonu, doğrulama hataları), giriş
 * (doğru/yanlış şifre), 5 hatalı denemede hesap kilidi, şifre sıfırlama
 * (token ÜRETMELİ ama HAM token DB'YE YAZILMAMALI — yalnız sha256 hash),
 * tek kullanımlık token ve süresi dolmuş token reddi.
 *
 * Performans notu: bcrypt maliyeti Config::set('security.password.cost', 4)
 * ile düşürülür (yalnız bu test sürecinde); dosya sonunda eski değere döner.
 * Tam yol: tests/CustomerAuthTest.php
 */
use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Session;
use App\Database\MigrationRunner;
use App\Exceptions\ValidationException;
use App\Services\Auth\CustomerAuthService;

return static function (TestRunner $t): void {
    $host       = (string) Config::get('database.host', '127.0.0.1');
    $port       = (int) Config::get('database.port', 3306);
    $charset    = (string) Config::get('database.charset', 'utf8mb4');
    $username   = (string) Config::get('database.username', 'root');
    $password   = (string) Config::get('database.password', '');
    $originalDb = (string) (env('DB_DATABASE') ?: 'xml_ecommerce'); // env tabanlı: önceki test dosyalarının Config::set sızıntısından bağışık
    $testDb     = $originalDb . '_test3_auth';
    $originalCost = (int) Config::get('security.password.cost', 12);

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
        $t->addTest('CustomerAuth: DB sunucusuna erişilemedi', static fn (): never => throw new TestSkip($reason));
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

    /** Session durumunu temizler. */
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

    /** Repository ile doğrudan müşteri yazar (bcrypt maliyetinden bağımsız hız). */
    $insertCustomer = static function (string $email, string $plainPassword) use ($useDb): int {
        $useDb();
        $stmt = Database::pdo()->prepare(
            'INSERT INTO customers (first_name, last_name, email, password_hash, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, "active", NOW(), NOW())'
        );
        $stmt->execute(['Test', 'Müşteri', $email, CustomerAuthService::hashPassword($plainPassword)]);
        return (int) Database::pdo()->lastInsertId();
    };

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

        // bcrypt maliyeti bu test sürecinde düşürülür (kilit mantığını etkilemez)
        Config::set('security.password.cost', 4);

        $useDb();

        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        $probe->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $migrationPdo = Database::connect($host, $port, $testDb, $charset, $username, $password);
        $applied = (new MigrationRunner($migrationPdo))->runPending();
        if (count($applied) !== 31) {
            throw new RuntimeException('Beklenmedik migration sayısı: ' . count($applied));
        }

        // Giriş testi için hazır kullanıcı (attempt doğru/yanlış şifre)
        $userB = $insertCustomer('auth-b@example.test', 'Parola!234');

        $state = ['userB' => $userB];
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

    $userB = (int) $state['userB'];

    /** Servisi TEST DB üzerinde taze üretir. */
    $auth = static function () use ($useDb): CustomerAuthService {
        $useDb();
        return new CustomerAuthService();
    };

    /* ---------------- testler (kayıt sırası = çalışma sırası) ---------------- */

    $t->addTest('CustomerAuth: register geçerli kayıt — satır oluşur, e-posta küçük harfe indirgenir, oturum açılır', static function () use ($auth, $resetSession, $scalar): void {
        $resetSession();

        $row = $auth()->register([
            'first_name'            => 'Ayşe',
            'last_name'             => 'Yılmaz',
            'email'                 => 'Ayse.Yilmaz@Example.COM',
            'phone'                 => '+90 555 111 22 33',
            'password'              => 'GucluParola1',
            'password_confirmation' => 'GucluParola1',
        ]);

        Assert::true((int) $row['id'] > 0, 'Kayıt id dönmeli');
        Assert::same('ayse.yilmaz@example.com', (string) $row['email'], 'E-posta küçük harfe indirgenmeli');
        Assert::same('active', (string) $row['status']);
        Assert::true(str_starts_with((string) $row['password_hash'], '$2y$'), 'password_hash bcrypt olmalı');

        // DB'de gerçekten var mı?
        Assert::same(1, (int) $scalar('SELECT COUNT(*) FROM customers WHERE email = ?', ['ayse.yilmaz@example.com']));
        Assert::same('Ayşe', (string) $scalar('SELECT first_name FROM customers WHERE id = ?', [(int) $row['id']]));

        // Oturum açılmış olmalı
        $authService = $auth();
        Assert::true($authService->check(), 'register oturum açmalı');
        Assert::same((int) $row['id'], (int) $authService->currentCustomerId());
        Assert::same('Ayşe Yılmaz', (string) Session::get('customer_name'));

        $resetSession();
    });

    $t->addTest('CustomerAuth: duplicate e-posta ve kısa şifre ValidationException verir', static function () use ($auth, $resetSession, $expectErrors): void {
        $resetSession();

        // Aynı adres (büyük/küçük harf varyantı) → duplicate
        $expectErrors(static fn () => $auth()->register([
            'first_name'            => 'İkinci',
            'last_name'             => 'Kişi',
            'email'                 => 'AYSE.YILMAZ@example.com',
            'phone'                 => '',
            'password'              => 'GucluParola1',
            'password_confirmation' => 'GucluParola1',
        ]), ['email'], 'Duplicate e-posta');

        // Kısa şifre
        $expectErrors(static fn () => $auth()->register([
            'first_name'            => 'Üçüncü',
            'last_name'             => 'Kişi',
            'email'                 => 'auth-c2@example.test',
            'phone'                 => '',
            'password'              => 'kisa',
            'password_confirmation' => 'kisa',
        ]), ['password'], 'Kısa şifre');

        // Şifre onayı uyuşmuyor
        $expectErrors(static fn () => $auth()->register([
            'first_name'            => 'Dördüncü',
            'last_name'             => 'Kişi',
            'email'                 => 'auth-c3@example.test',
            'phone'                 => '',
            'password'              => 'GucluParola1',
            'password_confirmation' => 'FarkliParola1',
        ]), ['password_confirmation'], 'Onay uyuşmazlığı');

        $resetSession();
    });

    $t->addTest('CustomerAuth: attempt doğru/yanlış şifre; başarılı girişte sayaç sıfırlanır; logout oturumu kapatır', static function () use ($auth, $resetSession, $scalar, $userB): void {
        $resetSession();
        // Üretimde HTTP front controller her zaman Session::start() yapar; CLI testte de başlat
        // (session_regenerate_id → oturum sabitleme koruması yalnız aktif oturumda çalışır).
        if (session_status() !== PHP_SESSION_ACTIVE) {
            Session::start();
        }
        $svc = $auth();

        Assert::false($svc->attempt('auth-b@example.test', 'YanlisSifre1', '127.0.0.1', 'TestRunner'), 'Yanlış şifre reddedilmeli');
        Assert::false($svc->check(), 'Başarısız girişte oturum açılmamalı');

        Assert::true($svc->attempt('auth-b@example.test', 'Parola!234', '127.0.0.1', 'TestRunner'), 'Doğru şifre kabul edilmeli');
        Assert::true($svc->check());
        Assert::same($userB, (int) $svc->currentCustomerId());
        Assert::same(0, (int) $scalar('SELECT failed_attempts FROM customers WHERE id = ?', [$userB]), 'Başarılı giriş sayaçı sıfırlamalı');

        Assert::false($svc->attempt('auth-b@example.test', 'YanlisSifre1', '127.0.0.1', 'TestRunner'), 'Girişliyken bile yanlış şifre reddedilmeli');
        Assert::same(1, (int) $scalar('SELECT failed_attempts FROM customers WHERE id = ?', [$userB]));

        // Oturum sabitleme koruması: session id yenilenmiş olmalı
        Assert::true(session_status() === PHP_SESSION_ACTIVE, 'Oturum aktif olmalı [status=' . session_status() . ' cid=' . var_export(Session::get('customer_id'), true) . ']');

        $svc->logout();
        Assert::false($svc->check(), 'logout sonrası oturum kapalı olmalı');
        Assert::same(null, Session::get('customer_id'));
        $resetSession();
    });

    $t->addTest('CustomerAuth: 5 hatalı denemede hesap kilitlenir ve 6. deneme (doğru şifreyle bile) reddedilir', static function () use ($auth, $resetSession, $exec, $scalar, $insertCustomer): void {
        $resetSession();
        $userId = $insertCustomer('auth-lock@example.test', 'DogruParola1');
        $svc = $auth();

        for ($i = 1; $i <= 5; $i++) {
            $result = $svc->attempt('auth-lock@example.test', 'YanlisSifre' . $i, '127.0.0.1', 'TestRunner');
            Assert::false($result, $i . '. hatalı deneme reddedilmeli');
        }

        $attempts = (int) $scalar('SELECT failed_attempts FROM customers WHERE id = ?', [$userId]);
        $lockedUntil = $scalar('SELECT locked_until FROM customers WHERE id = ?', [$userId]);
        Assert::same(5, $attempts, '5 başarısız deneme sayılmalı');
        Assert::true($lockedUntil !== null, '5. denemede locked_until SET edilmeli');
        Assert::true(strtotime((string) $lockedUntil) > time(), 'Kilit gelecekte olmalı (15 dk)');

        // 6. deneme — DOĞRU şifreyle bile kilitli
        Assert::false($svc->attempt('auth-lock@example.test', 'DogruParola1', '127.0.0.1', 'TestRunner'), 'Kilitli hesaba doğru şifreyle giriş reddedilmeli');
        Assert::false($svc->check(), 'Kilitli girişte oturum AÇILMAMALI');

        // Kilit süresi geçmiş gibi simüle → giriş açılır
        $exec('UPDATE customers SET locked_until = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$userId]);
        Assert::true($svc->attempt('auth-lock@example.test', 'DogruParola1', '127.0.0.1', 'TestRunner'), 'Kilit süresi geçince doğru şifre kabul edilmeli');
        $resetSession();
    });

    $t->addTest('CustomerAuth: sendResetLink — bilinen e-posta token üretir, DB yalnız sha256 HASH saklar; ikinci talep eskisini siler; bilinmeyen → null', static function () use ($auth, $insertCustomer, $scalar): void {
        $userId = $insertCustomer('auth-reset@example.test', 'EskiParola1');
        $svc = $auth();

        $token1 = $svc->sendResetLink('auth-reset@example.test');
        Assert::true(is_string($token1) && $token1 !== '', 'Bilinen e-posta token döndürmeli');
        Assert::same(64, strlen((string) $token1), 'Token 32 byte hex = 64 karakter');
        Assert::true(ctype_xdigit((string) $token1), 'Token hex olmalı');

        $storedHash = (string) $scalar('SELECT token_hash FROM customer_password_resets WHERE customer_id = ? LIMIT 1', [$userId]);
        Assert::same(64, strlen($storedHash), 'DB token_hash 64 karakter (sha256 hex) olmalı');
        Assert::same(hash('sha256', (string) $token1), $storedHash, 'DB hash\'i sha256(raw token) olmalı');
        Assert::same(0, (int) $scalar('SELECT COUNT(*) FROM customer_password_resets WHERE token_hash = ?', [(string) $token1]),
            'HAM TOKEN DB\'DE SAKLANMAMALI (yalnız hash)');

        // İkinci talep: müşteri başına tek aktif istek (eskisi silinir)
        $token2 = $svc->sendResetLink('auth-reset@example.test');
        Assert::true(is_string($token2) && $token2 !== '');
        Assert::same(1, (int) $scalar('SELECT COUNT(*) FROM customer_password_resets WHERE customer_id = ?', [$userId]), 'Eski talep silinmeli');
        Assert::same(hash('sha256', (string) $token2), (string) $scalar('SELECT token_hash FROM customer_password_resets WHERE customer_id = ?', [$userId]));

        // Bilinmeyen e-posta → null (kaydın varlığı ifşa edilmez)
        Assert::same(null, $svc->sendResetLink('yok-boyle-biri@example.test'), 'Bilinmeyen e-posta NULL döndürmeli');
    });

    $t->addTest('CustomerAuth: resetPassword şifreyi değiştirir; token TEK kullanımlık (eski token tekrar kullanılamaz)', static function () use ($auth, $insertCustomer, $expectErrors, $scalar): void {
        $userId = $insertCustomer('auth-reset2@example.test', 'EskiParola2');
        $svc = $auth();

        $token = $svc->sendResetLink('auth-reset2@example.test');
        Assert::true(is_string($token) && $token !== '');

        // Kısa şifre: token HENÜZ tüketilmez
        $expectErrors(static fn () => $svc->resetPassword((string) $token, 'kisa', 'kisa'), ['password'], 'Kısa yeni şifre');
        Assert::same(null, $scalar('SELECT used_at FROM customer_password_resets WHERE token_hash = ?', [hash('sha256', (string) $token)]),
            'Hatalı denemede token tüketilmemeli');

        // Geçerli kullanım
        $svc->resetPassword((string) $token, 'YeniParola2!', 'YeniParola2!');

        $hash = (string) $scalar('SELECT password_hash FROM customers WHERE id = ?', [$userId]);
        Assert::true(password_verify('YeniParola2!', $hash), 'Yeni şifre doğrulanmalı');
        Assert::false(password_verify('EskiParola2', $hash), 'Eski şifre geçersiz olmalı');
        Assert::true($scalar('SELECT used_at FROM customer_password_resets WHERE token_hash = ?', [hash('sha256', (string) $token)]) !== null, 'Token used_at ile işaretlenmeli');

        // Aynı token tekrar → findValid NULL → ValidationException
        $expectErrors(static fn () => $svc->resetPassword((string) $token, 'BaskaParola3!', 'BaskaParola3!'), ['token'], 'Kullanılmış token reddedilmeli');

        // Yeni şifreyle giriş çalışır
        Assert::true($svc->attempt('auth-reset2@example.test', 'YeniParola2!', '127.0.0.1', 'TestRunner'), 'Yeni şifreyle giriş başarıli olmalı');
    });

    $t->addTest('CustomerAuth: süresi dolmuş token ValidationException verir', static function () use ($auth, $insertCustomer, $expectErrors, $exec): void {
        $insertCustomer('auth-reset3@example.test', 'EskiParola3');
        $svc = $auth();

        $token = $svc->sendResetLink('auth-reset3@example.test');
        Assert::true(is_string($token) && $token !== '');

        // DB'de süre geçmişe çekilir (migration'la gelen kolon üzerinden)
        $exec('UPDATE customer_password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE)');

        $expectErrors(static fn () => $svc->resetPassword((string) $token, 'YeniParola4!', 'YeniParola4!'), ['token'], 'Süresi dolmuş token reddedilmeli');
    });

    $t->addTest('CustomerAuth: test DB temizlenir, config ve oturum geri yüklenir', static function () use ($probe, $testDb, $originalDb, $originalCost, $resetSession): void {
        $resetSession();
        Config::set('security.password.cost', $originalCost);
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        Config::set('database.database', $originalDb);
        Database::disconnect();
        Assert::same($originalDb, Config::get('database.database'), 'Orijinal DB adı geri yüklenmeli');
        Assert::same($originalCost, (int) Config::get('security.password.cost', 12), 'bcrypt maliyeti geri yüklenmeli');
        Assert::same(null, Session::get('customer_id'), 'customer_id oturumdan silinmiş olmalı');
    });
};
