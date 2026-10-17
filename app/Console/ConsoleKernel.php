<?php

declare(strict_types=1);

namespace App\Console;

/**
 * CLI komut çekirdeği: argüman ayrıştırma + komut kaydı.
 *
 * Tam yol: app/Console/ConsoleKernel.php
 */
final class ConsoleKernel
{
    /** @var array<string, callable(list<string>):int> */
    private array $commands = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /** Giriş noktası — exit kodu döndürür. @param list<string> $argv */
    public static function main(array $argv): int
    {
        $kernel = new self();
        $name = $argv[1] ?? 'list';
        $options = array_slice($argv, 2);

        return $kernel->run($name, $options);
    }

    public function run(string $name, array $options): int
    {
        if (!isset($this->commands[$name])) {
            fwrite(STDERR, "Bilinmeyen komut: {$name}\n\n");
            $this->printHelp();
            return 1;
        }

        return ($this->commands[$name])(array_values($options));
    }

    /** "--feed=1", "--feed 1", "--all" biçimlerini ayrıştırır. @return array<string,string|true> */
    public static function parseOptions(array $args): array
    {
        $options = [];
        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $arg = (string) $args[$i];
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $body = substr($arg, 2);
            if (str_contains($body, '=')) {
                [$key, $value] = explode('=', $body, 2);
                $options[$key] = $value;
                continue;
            }
            $next = $args[$i + 1] ?? null;
            if ($next !== null && !str_starts_with((string) $next, '--')) {
                $options[$body] = (string) $next;
                $i++;
            } else {
                $options[$body] = true;
            }
        }
        return $options;
    }

    private function registerDefaults(): void
    {
        $this->commands['list'] = fn (): int => $this->printHelp();

        $this->commands['xml:sync'] = fn (array $args): int => (new Commands\XmlSyncCommand())->run(self::parseOptions($args));
        $this->commands['feed:list'] = fn (array $args): int => (new Commands\FeedListCommand())->run(self::parseOptions($args));
        $this->commands['xml:preview'] = fn (array $args): int => (new Commands\XmlPreviewCommand())->run(self::parseOptions($args));
        $this->commands['order:expire'] = fn (array $args): int => (new Commands\OrderExpireCommand())->run(self::parseOptions($args));
        // AŞAMA 5 — tedarikçi siparişleri, kargo ve bildirimler
        $this->commands['supplier:process'] = fn (array $args): int => (new Commands\SupplierProcessCommand())->run(self::parseOptions($args));
        $this->commands['supplier:query'] = fn (array $args): int => (new Commands\SupplierQueryCommand())->run(self::parseOptions($args));
        $this->commands['shipments:sync'] = fn (array $args): int => (new Commands\ShipmentsSyncCommand())->run(self::parseOptions($args));
        $this->commands['notifications:send'] = fn (array $args): int => (new Commands\NotificationsSendCommand())->run(self::parseOptions($args));
        // AŞAMA 6 — üretim yapılandırma denetimi
        $this->commands['doctor'] = fn (array $args): int => (new Commands\DoctorCommand())->run(self::parseOptions($args));
    }

    private function printHelp(): int
    {
        echo <<<TXT
        XML E-Ticaret konsolu

        Kullanım:
          php bin/console <komut> [seçenekler]

        Komutlar:
          xml:sync     XML kaynak senkronizasyonu
                       --feed=ID    Belirli kaynağı hemen senkronize et
                       --all        Zamanı gelen tüm aktif URL kaynaklarını işle
                       --force      Zamanlamayı yok say, tüm aktif kaynakları işle
          feed:list    XML kaynaklarını listele
          xml:preview  Kaynak dosyasını eşleme profiliyle önizle
                       --feed=ID    Kaynak ID
                       --file=YOL   (opsiyonel) farklı bir XML dosyası
                       --limit=N    (opsiyonel, varsayılan 5) satır sayısı
          order:expire Süresi geçen ödeme bekleyen siparişleri iptal et (Aşama 4)
                       --minutes=N  (opsiyonel) süre eşiği; varsayılan ORDER_EXPIRE_MINUTES
          supplier:process  Tedarikçi siparişlerini işler (Aşama 5)
                       --id=N       Tek tedarikçi siparişini gönder
                       --limit=N    (varsayılan 50) işlenecek kayıt sayısı
          supplier:query    Durumu belirsiz kayıtları tedarikçiden sorgular (Aşama 5)
                       --id=N       Tek kayıt sorgula
          shipments:sync    Kargo takip bilgilerini adaptörden senkronlar (Aşama 5)
          notifications:send Bildirim kuyruğunu boşaltır (Aşama 5)
                       --limit=N    (varsayılan 100)
          doctor       Üretim yapılandırma denetimi: PASS/WARN/FAIL raporu (Aşama 6)
                       Çıkış kodu 0 = kritik kontroller geçti, 1 = en az bir FAIL
          list         Bu yardım metni

        Örnek crontab:
          */10 * * * * cd /var/www/xml-ecommerce && php bin/console xml:sync --all >> storage/logs/cron.log 2>&1
          */10 * * * * cd /var/www/xml-ecommerce && php bin/console order:expire  >> storage/logs/cron.log 2>&1
          */5  * * * * cd /var/www/xml-ecommerce && php bin/console supplier:process >> storage/logs/cron.log 2>&1
          */15 * * * * cd /var/www/xml-ecommerce && php bin/console supplier:query >> storage/logs/cron.log 2>&1
          */30 * * * * cd /var/www/xml-ecommerce && php bin/console shipments:sync >> storage/logs/cron.log 2>&1
          */5  * * * * cd /var/www/xml-ecommerce && php bin/console notifications:send >> storage/logs/cron.log 2>&1

        TXT;
        return 0;
    }
}
