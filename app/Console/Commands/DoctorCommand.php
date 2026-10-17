<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use Throwable;

/**
 * doctor — üretim yapılandırma denetimi (Aşama 6 §2 otomasyonu).
 *
 *   php bin/console doctor
 *
 * PASS/WARN/FAIL tablosu + özet basar.
 * Çıkış kodu: 0 = tüm KRİTİK kontroller geçti (WARN olabilir),
 *             1 = en az bir FAIL var.
 *
 * Kural: FAIL yalnızca canlıda veri kaybı/güvenlik açığı/çalışmama riski doğuran
 * durumlarda; WARN ise canlıya geçişten önce gözden geçirilmesi önerilen risklerde.
 * Bu komut GİZLİ değerleri (APP_KEY, DB parolası, iyzico secret) ASLA yazdırmaz.
 *
 * Tam yol: app/Console/Commands/DoctorCommand.php
 */
final class DoctorCommand
{
    /** @var list<array{0:string,1:string,2:string}> kontrol adı, sonuç, detay */
    private array $rows = [];

    private int $pass = 0;
    private int $warn = 0;
    private int $fail = 0;

    /** @param array<string,string|true> $options */
    public function run(array $options): int
    {
        $this->checkEnvironment();
        $this->checkDatabase();
        $this->checkSession();
        $this->checkPayment();
        $this->checkMail();
        $this->checkStorage();
        $this->checkRuntime();
        $this->checkMigrations();
        $this->checkCrontab();
        $this->checkEnvFilePerms();

        $this->printReport();

        return $this->fail > 0 ? 1 : 0;
    }

    /* -----------------------------------------------------------------
     * Kontroller
     * ----------------------------------------------------------------- */

    private function checkEnvironment(): void
    {
        $env = (string) Config::get('app.env', 'production');
        $isProd = $env === 'production';

        // APP_DEBUG — config/app.php 'debug' anahtarından (yoksa env yedeği)
        $debugRaw = (string) (Config::get('app.debug') ?? \App\Core\Env::get('APP_DEBUG', '0'));
        $debugOn = in_array(strtolower($debugRaw), ['1', 'true', 'yes', 'on'], true);
        if ($debugOn) {
            // Hata detaylarını kullanıcıya sızdırır → her ortamda kritik değil ama prod'da FAIL
            $this->add($isProd, 'APP_DEBUG', $debugRaw !== '' ? 'açık (' . $debugRaw . ')' : 'açık');
        } else {
            $this->add(true, 'APP_DEBUG', 'kapalı — hata detayları gizli');
        }

        // APP_URL — production'da https beklenir
        $url = (string) Config::get('app.url', '');
        if ($url === '') {
            $this->add(false, 'APP_URL', 'tanımsız');
        } elseif (str_starts_with($url, 'https://')) {
            $this->add(true, 'APP_URL', 'https yapılandırılmış');
        } elseif ($isProd) {
            $this->add(false, 'APP_URL', 'https değil (' . $url . ') — TLS sonrası https:// ile başlamalı');
        } else {
            $this->add(true, 'APP_URL', 'https değil — ' . $env . ' ortamında kabul edilebilir (' . $url . ')');
        }

        // APP_KEY — Cipher (AES-256-GCM) için ≥ 32 byte olmalı
        $key = (string) Config::get('security.app_key', '');
        $keyBytes = $key !== '' ? strlen((string) base64_decode($key, true)) : 0;
        if ($keyBytes >= 32) {
            $this->add(true, 'APP_KEY', $keyBytes . ' byte — yeterli');
        } else {
            $this->add(false, 'APP_KEY', 'eksik/kısa (' . $keyBytes . ' byte) — "openssl rand -base64 32" ile üretin');
        }
    }

    private function checkDatabase(): void
    {
        $user = (string) Config::get('database.username', '');
        $pass = (string) Config::get('database.password', '');
        $db   = (string) Config::get('database.database', '');

        // Ayrı uygulama kullanıcısı zorunlu (root yasak)
        $rootUser = $user === 'root';
        $this->add(!$rootUser, 'DB kullanıcısı', $rootUser
            ? 'root kullanılıyor — AYRI uygulama kullanıcısı oluşturun (docs/deployment.md §6)'
            : 'root değil (' . $user . ') — iyi');

        if ($pass === '') {
            $this->add(false, 'DB parolası', 'boş — güçlü parola atayın');
        } else {
            $this->add(true, 'DB parolası', 'atanmış (gösterilmiyor)');
        }

        // Bağlantı + veritabanı varlığı
        try {
            Database::pdo()->query('SELECT 1');
            $this->add(true, 'DB bağlantısı', 'başarılı (' . $db . ')');
        } catch (Throwable $e) {
            $this->add(false, 'DB bağlantısı', 'başarısız — kimlik bilgileri/sunucu erişilemez');
        }
    }

    private function checkSession(): void
    {
        $secure   = strtolower((string) Config::get('security.session.secure', 'auto'));
        $lifetime = (int) Config::get('security.session.lifetime', 2700);

        $ok = in_array($secure, ['auto', 'true'], true);
        if (!$ok) {
            $this->add(false, 'SESSION_SECURE', 'false — çerezler şifresiz bağlantıda taşınabilir');
        } elseif ($secure === 'auto') {
            $this->add(true, 'SESSION_SECURE', 'auto — üretimde (https) "true" önerilir');
        } else {
            $this->add(true, 'SESSION_SECURE', 'true — çerezler yalnız HTTPS');
        }

        if ($lifetime >= 600 && $lifetime <= 86400) {
            $this->add(true, 'SESSION_LIFETIME', $lifetime . ' sn — makul aralık');
        } else {
            $this->add(false, 'SESSION_LIFETIME', $lifetime . ' sn — 600–86400 aralığında olmalı');
        }
    }

    private function checkPayment(): void
    {
        $mode   = strtolower((string) Config::get('payment.mode', 'mock'));
        $apiKey = (string) Config::get('payment.api_key', '');
        $secret = (string) Config::get('payment.secret_key', '');

        if ($mode === 'live') {
            if ($apiKey !== '' && $secret !== '') {
                $this->add(true, 'IYZICO_MODE', 'live + anahtarlar atanmış (gösterilmiyor)');
            } else {
                $this->add(false, 'IYZICO_MODE', 'live ama API_KEY/SECRET_KEY boş — ödeme çağrıları başarısız olur');
            }
        } elseif ($mode === 'sandbox') {
            $this->add($apiKey !== '' && $secret !== '', 'IYZICO_MODE', 'sandbox — canlıya geçişte live + gerçek anahtar gereklidir');
        } else {
            $this->add(true, 'IYZICO_MODE', 'mock — CANLI SATIŞ YAPILAMAZ (ödemeler simülasyon)');
        }
    }

    private function checkMail(): void
    {
        $driver = strtolower((string) Config::get('mail.driver', 'log'));
        if ($driver === 'smtp') {
            $host = (string) Config::get('mail.smtp.host', '');
            $this->add($host !== '', 'MAIL_DRIVER', $host !== ''
                ? 'smtp (' . $host . ')'
                : 'smtp ama SMTP_HOST boş — e-postalar gönderilemez');
        } elseif ($driver === 'log') {
            $this->add(true, 'MAIL_DRIVER', 'log — e-postalar storage/logs/mail.log\'a yazılıyor (CANLI DEĞİL)');
        } else {
            $this->add(false, 'MAIL_DRIVER', 'bilinmeyen sürücü: ' . $driver);
        }
    }

    private function checkStorage(): void
    {
        foreach (['logs', 'imports', 'cache', 'locks'] as $dir) {
            $path = App::storagePath($dir);
            if (!is_dir($path)) {
                $this->add(false, 'storage/' . $dir, 'dizin yok — oluşturun ve www-data\'ya verin');
                continue;
            }
            $this->add(is_writable($path), 'storage/' . $dir, is_writable($path)
                ? 'yazılabilir'
                : 'YAZILAMAZ — chown www-data + 750 gerekli');
        }

        // public/index.php mevcut mu?
        $index = App::basePath('public/index.php');
        $this->add(is_file($index), 'public/index.php', is_file($index) ? 'mevcut' : 'BULUNAMADI — web kökü bozuk');

        // storage/ web kökünün dışında mı?
        $storage = realpath(App::storagePath());
        $public  = realpath(App::basePath('public'));
        $outside = $storage !== false && $public !== false && !str_starts_with($storage, $public);
        $this->add($outside, 'storage konumu', $outside
            ? 'public/ dışında — web\'den erişilemez'
            : 'storage public/ İÇİNDE — ciddi yapılandırma hatası');
    }

    private function checkRuntime(): void
    {
        $version = PHP_VERSION;
        $this->add(version_compare($version, '8.2.0', '>='), 'PHP sürümü', $version . ' (≥ 8.2 gerekli)');

        $required = ['pdo_mysql', 'xmlreader', 'xmlwriter', 'mbstring', 'curl', 'openssl', 'json', 'fileinfo'];
        $missing = array_values(array_filter($required, static fn (string $ext): bool => !extension_loaded($ext)));
        $this->add($missing === [], 'PHP eklentileri', $missing === []
            ? implode(', ', $required) . ' tamam'
            : 'EKSİK: ' . implode(', ', $missing));

        $optional = array_values(array_filter(['gd', 'zip', 'intl', 'opcache'], static fn (string $ext): bool => !extension_loaded($ext)));
        $this->add($optional === [] ? true : 'warn', 'PHP eklentileri (önerilen)', $optional === []
            ? 'gd, zip, intl, opcache tamam'
            : 'önerilen eksik: ' . implode(', ', $optional) . ' (gd: görsel işleme, opcache: performans)');
    }

    private function checkMigrations(): void
    {
        try {
            $stmt = Database::pdo()->query('SELECT COUNT(*) FROM migrations');
            $applied = (int) ($stmt === false ? 0 : $stmt->fetchColumn());
        } catch (Throwable) {
            $this->add(false, 'Migration durumu', 'migrations tablosu okunamadı — php database/migrate.php çalıştırın');
            return;
        }

        $files = glob(App::basePath('database/migrations/*.php')) ?: [];
        $count = count($files);
        if ($count === 0) {
            $this->add(false, 'Migration durumu', 'migration dosyası bulunamadı');
        } elseif ($applied < $count) {
            $this->add(false, 'Migration durumu', $applied . '/' . $count . ' uygulandı — php database/migrate.php çalıştırın');
        } else {
            $this->add(true, 'Migration durumu', $applied . '/' . $count . ' — şema güncel');
        }
    }

    private function checkCrontab(): void
    {
        // Uygulama cron'u sunucu tarafında kurulur; içeriden doğrulanamaz → bilgi notu
        $this->add('warn', 'Cron görevleri', 'sunucuda doğrulayın: "crontab -u www-data -l" — şablon: cron/crontab.example');
    }

    private function checkEnvFilePerms(): void
    {
        $envFile = App::basePath('.env');
        if (!is_file($envFile)) {
            $this->add('warn', '.env izni', '.env dosyası yok — .env.production.example\'dan kopyalayın');
            return;
        }
        $perms = (int) (fileperms($envFile) & 0777);
        if ($perms <= 0640) {
            $this->add(true, '.env izni', '0' . decoct($perms) . ' — güvenli (≤ 0640)');
        } else {
            $this->add('warn', '.env izni', '0' . decoct($perms) . ' — 0640 önerilir: chmod 640 .env && chown www-data:www-data .env');
        }
    }

    /* -----------------------------------------------------------------
     * Rapor
     * ----------------------------------------------------------------- */

    /** @param string|bool $ok true/false = PASS/FAIL, "warn" = WARN */
    private function add(string|bool $ok, string $name, string $detail): void
    {
        if ($ok === true) {
            $status = 'PASS';
            $this->pass++;
        } elseif ($ok === 'warn') {
            $status = 'WARN';
            $this->warn++;
        } else {
            $status = 'FAIL';
            $this->fail++;
        }
        $this->rows[] = [$name, $status, $detail];
    }

    private function printReport(): void
    {
        echo "Üretim yapılandırma denetimi (doctor)\n";
        echo str_repeat('─', 96) . "\n";
        printf("%-30s %-6s %s\n", 'KONTROL', 'SONUÇ', 'DETAY');
        echo str_repeat('─', 96) . "\n";
        foreach ($this->rows as [$name, $status, $detail]) {
            printf("%s %-6s %s\n", self::pad($name, 30), $status, $detail);
        }
        echo str_repeat('─', 96) . "\n";
        printf("ÖZET: %d PASS, %d WARN, %d FAIL — %s\n", $this->pass, $this->warn, $this->fail, $this->fail > 0
            ? 'DÜZELTMELER GEREKLİ'
            : 'kritik kontroller geçti');
        if ($this->warn > 0) {
            echo "Not: WARN'lar canlıya geçiş öncesi gözden geçirilmelidir (docs/go-live-checklist.md).\n";
        }
    }

    /** Çok baytlı dizgileri görsel genişliğe göre sağa boşlukla (TR karakter hizalaması). */
    private static function pad(string $value, int $width): string
    {
        $len = mb_strwidth($value, 'UTF-8');
        return $len >= $width ? $value : $value . str_repeat(' ', $width - $len);
    }
}
