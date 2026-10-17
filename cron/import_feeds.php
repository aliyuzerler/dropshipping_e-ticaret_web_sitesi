#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cron girişi: zamanı gelen XML feed'lerini indirir + doğrular + loglar.
 *
 * Çalıştırma (crontab, her 10 dakikada bir):
 *   cd /var/www/xml-ecommerce && /usr/bin/php cron/import_feeds.php >> storage/logs/cron.log 2>&1
 *
 * Kilit: flock ile eşzamanlı çalışma engellenir (storage/locks/import_feeds.lock).
 * Tam yol: cron/import_feeds.php
 */
require_once dirname(__DIR__) . '/app/Core/Autoloader.php';
\Autoloader::register();
require_once dirname(__DIR__) . '/app/Core/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu betik yalnız CLI'dan çalıştırılabilir.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');
date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'UTC'));
App\Core\App::boot(BASE_PATH);

use App\Core\Logger;
use App\Services\Import\FeedImportService;

/* --- Eşzamanlılık kilidi --- */
$lockDir = storage_path('locks');
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0750, true);
}
$lockFile = fopen($lockDir . '/import_feeds.lock', 'c');
if ($lockFile === false || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    echo "[" . date('c') . "] Önceki import süreci hala çalışıyor — bu çalıştırma atlandı.\n";
    exit(0);
}

try {
    echo '[' . date('c') . "] XML feed import başlıyor...\n";
    $service = new FeedImportService();
    $summary = $service->runDueFeeds();

    printf(
        "[%s] Tamamlandı: %d feed işlendi, %d başarılı, %d başarısız.\n",
        date('c'),
        $summary['processed'],
        $summary['success'],
        $summary['failed']
    );

    Logger::info('app', 'cron.import_feeds.done', $summary);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] HATA: ' . $e->getMessage() . PHP_EOL);
    Logger::error('app', 'cron.import_feeds.failed', ['reason' => $e->getMessage()]);
    exit(1);
} finally {
    flock($lockFile, LOCK_UN);
    fclose($lockFile);
}
