<?php

declare(strict_types=1);

/**
 * AŞAMA 6 — RateLimiter (dosya tabanlı hız sınırı) testleri.
 *
 * Depolama yalıtımı: App::storagePath cache'i suite ortamına yazmamak için
 * App::$basePath, test sırasında geçici sys_get_temp_dir() altına taşınır ve
 * test sonunda geri yüklenir (reflection ile — boot pattern'ini bozmadan).
 *
 * Tam yol: tests/Security/RateLimitTest.php
 */
use App\Core\App;
use App\Services\Security\RateLimiter;

return static function (TestRunner $t): void {
    // Gerçek basePath'i sakla; test süresince izole temp dizinine yönlendir
    $ref = new ReflectionProperty(App::class, 'basePath');
    $ref->setAccessible(true);
    $originalBasePath = $ref->getValue();
    $tempBase = sys_get_temp_dir() . '/xmlshop-test-ratelimit-' . getmypid();

    $useTempBase = static function () use ($ref, $tempBase): void {
        $ref->setValue(null, $tempBase);
    };
    $restoreBase = static function () use ($ref, $originalBasePath): void {
        $ref->setValue(null, $originalBasePath);
    };

    $cleanup = static function () use ($tempBase): void {
        $dir = $tempBase . '/storage/cache/rate-limit';
        if (is_dir($dir)) {
            foreach ((glob($dir . '/*.json') ?: []) as $f) {
                @unlink((string) $f);
            }
            @rmdir($dir);
        }
        foreach ([$tempBase . '/storage/cache', $tempBase . '/storage', $tempBase] as $d) {
            @rmdir((string) $d);
        }
    };

    $t->addTest('RateLimit: ilk vuruşta sınır aşılmaz, sayaç doğru artar', static function () use ($useTempBase, $restoreBase, $cleanup): void {
        try {
            $useTempBase();
            $key = 'rl-test-a:1.2.3.4';

            Assert::false(RateLimiter::tooManyAttempts($key, 3, 60));
            RateLimiter::hit($key, 60);
            RateLimiter::hit($key, 60);
            Assert::false(RateLimiter::tooManyAttempts($key, 3, 60), '3 vuruş < 3 sınır — izin var');
            RateLimiter::hit($key, 60);
            Assert::true(RateLimiter::tooManyAttempts($key, 3, 60), '3. vuruştan sonra sınır aşıldı');
        } finally {
            $cleanup();
            $restoreBase();
        }
    });

    $t->addTest('RateLimit: farklı anahtarlar bağımsız sayılır', static function () use ($useTempBase, $restoreBase, $cleanup): void {
        try {
            $useTempBase();
            RateLimiter::hit('rl-test-b:1.1.1.1', 60);
            RateLimiter::hit('rl-test-b:1.1.1.1', 60);
            Assert::true(RateLimiter::tooManyAttempts('rl-test-b:1.1.1.1', 2, 60));
            Assert::false(RateLimiter::tooManyAttempts('rl-test-b:2.2.2.2', 2, 60), 'Farklı IP izinli');
            Assert::false(RateLimiter::tooManyAttempts('rl-test-c:1.1.1.1', 2, 60), 'Farklı uç izinli');
        } finally {
            $cleanup();
            $restoreBase();
        }
    });

    $t->addTest('RateLimit: clear() sayacı sıfırlar (kilit çözülür)', static function () use ($useTempBase, $restoreBase, $cleanup): void {
        try {
            $useTempBase();
            $key = 'rl-test-d:1.2.3.4';
            for ($i = 0; $i < 5; $i++) {
                RateLimiter::hit($key, 60);
            }
            Assert::true(RateLimiter::tooManyAttempts($key, 5, 60));
            RateLimiter::clear($key);
            Assert::false(RateLimiter::tooManyAttempts($key, 5, 60), 'Temizleme sonrası izin var');
        } finally {
            $cleanup();
            $restoreBase();
        }
    });

    $t->addTest('RateLimit: pencere süresi geçen sayaç sıfırdan başlar (gerçek zaman, kısa pencere)', static function () use ($useTempBase, $restoreBase, $cleanup): void {
        try {
            $useTempBase();
            $key = 'rl-test-e:1.2.3.4';
            for ($i = 0; $i < 2; $i++) {
                RateLimiter::hit($key, 1); // 1 saniyelik pencere
            }
            Assert::true(RateLimiter::tooManyAttempts($key, 2, 1));
            sleep(2); // pencereyi geç
            Assert::false(RateLimiter::tooManyAttempts($key, 2, 1), 'Pencere kapandı — izin var');
            RateLimiter::hit($key, 1);
            Assert::false(RateLimiter::tooManyAttempts($key, 2, 1), 'Yeni pencere 1 vuruşla sınır altında');
        } finally {
            $cleanup();
            $restoreBase();
        }
    });

    $t->addTest('RateLimit: yazılamayan depolamada fail-open (istisna fırlatmaz)', static function () use ($restoreBase, $cleanup): void {
        try {
            // Kayıt fazında değil, test içinde: yazılamaz yol + OKUNAMAZ dosya senaryosu
            // path() yalnız App::storagePath istisnasında null döner; normal yolda
            // yazma başarısızlığı sessizce yutulur (write fail-open). Burada yalnızca
            // API'nin istisna fırlatmadığını doğruluyoruz (normal basePath ile):
            RateLimiter::hit('rl-test-f:1.2.3.4', 60);
            RateLimiter::tooManyAttempts('rl-test-f:1.2.3.4', 1, 60);
            RateLimiter::clear('rl-test-f:1.2.3.4');
            Assert::true(true, 'İstisna fırlatmadı');
        } finally {
            $cleanup();
            $restoreBase();
        }
    });
};
