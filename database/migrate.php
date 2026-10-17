<?php

declare(strict_types=1);

/**
 * Migration CLI: php database/migrate.php [status]
 * Tam yol: database/migrate.php
 */
require_once dirname(__DIR__) . '/app/Core/Autoloader.php';
\Autoloader::register();
require_once dirname(__DIR__) . '/app/Core/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu betik yalnız CLI'da çalışır.\n");
    exit(1);
}

use App\Core\App;
use App\Core\Config;
use App\Core\Env;
use App\Core\Logger;
use App\Database\MigrationRunner;

define('BASE_PATH', dirname(__DIR__));
Env::load(BASE_PATH . '/.env');
Config::load(BASE_PATH . '/config');
date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));

// Logger path bağımlılığı için App::basePath bilgisini kur (boot etmeden)
App::boot(BASE_PATH);

$command = $argv[1] ?? 'migrate';

try {
    $runner = new MigrationRunner(\App\Core\Database::pdo());

    if ($command === 'status') {
        $applied = $runner->appliedMigrations();
        echo "Uygulanmış migration sayısı: " . count($applied) . PHP_EOL;
        foreach ($runner->loadMigrations() as $migration) {
            $done = in_array($migration->name(), $applied, true) ? '[x]' : '[ ]';
            echo $done . ' ' . $migration->name() . PHP_EOL;
        }
        exit(0);
    }

    echo "Migration'lar çalıştırılıyor...\n";
    $executed = $runner->runPending(static function (string $name): void {
        echo '  ✓ ' . $name . PHP_EOL;
    });

    if ($executed === []) {
        echo "Yapılandırılmamış migration yok — şema güncel.\n";
    } else {
        echo count($executed) . " migration uygulandı.\n";
    }
    Logger::info('app', 'migrate.done', ['applied' => count($executed)]);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . PHP_EOL);
    Logger::error('app', 'migrate.failed', ['reason' => $e->getMessage()]);
    exit(1);
}
