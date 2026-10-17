<?php

declare(strict_types=1);

/**
 * Seed CLI: php database/seed.php
 * Idempotent — tekrar çalıştırmak güvenlidir.
 * Tam yol: database/seed.php
 */
require_once dirname(__DIR__) . '/app/Core/Autoloader.php';
\Autoloader::register();
require_once dirname(__DIR__) . '/app/Core/helpers.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Bu betik yalnız CLI'da çalışır.\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
App\Core\Env::load(BASE_PATH . '/.env');
App\Core\Config::load(BASE_PATH . '/config');
date_default_timezone_set((string) App\Core\Config::get('app.timezone', 'UTC'));
App\Core\App::boot(BASE_PATH);

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Services\Auth\AdminAuthService;

try {
    $pdo = Database::pdo();

    /* --- 1) İlk admin kullanıcısı --- */
    $email = (string) Config::get('app.seed_admin_email', env('ADMIN_EMAIL', ''));
    if ($email === '') {
        $email = 'admin@example.local';
    }

    $stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn() === false) {
        $password = env('ADMIN_PASSWORD', '');
        $generated = false;
        if ($password === null || $password === '') {
            $password = bin2hex(random_bytes(9)); // 18 karakter rastgele
            $generated = true;
        }
        $pdo->prepare(
            'INSERT INTO admin_users (name, email, password_hash, role, status, created_at, updated_at)
             VALUES (?, ?, ?, "superadmin", "active", NOW(), NOW())'
        )->execute([
            env('ADMIN_NAME', 'Site Yöneticisi') ?? 'Site Yöneticisi',
            $email,
            AdminAuthService::hashPassword($password),
        ]);
        echo "Admin kullanıcısı oluşturuldu: $email\n";
        if ($generated) {
            echo "ŞİFRE (tek sefer gösterilir, hemen değiştirin): $password\n";
            echo "Kayıt: storage/logs/seed_admin_password.log\n";
            @file_put_contents(
                App\Core\App::storagePath('logs/seed_admin_password.log'),
                date('c') . " email=$email password=$password\n",
                FILE_APPEND
            );
        }
    } else {
        echo "Admin kullanıcısı zaten var: $email\n";
    }

    /* --- 2) Örnek kategoriler --- */
    $categories = [
        ['Elektronik', 'elektronik'],
        ['Moda', 'moda'],
        ['Ev & Yaşam', 'ev-yasam'],
        ['Spor & Outdoor', 'spor-outdoor'],
    ];
    $stmtCount = $pdo->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
    $stmtInsert = $pdo->prepare(
        'INSERT INTO categories (name, slug, is_active, sort_order, created_at, updated_at)
         VALUES (?, ?, 1, ?, NOW(), NOW())'
    );
    $order = 1;
    foreach ($categories as [$name, $slug]) {
        $stmtCount->execute([$slug]);
        if ($stmtCount->fetchColumn() === false) {
            $stmtInsert->execute([$name, $slug, $order]);
            echo "Kategori eklendi: $name\n";
        }
        $order++;
    }

    /* --- Tedarikçiler bilinçli olarak BOŞ bırakıldı --- */
    echo "\nNot: Tedarikçi (supplier) kayıtları örnek/uydurma veri içermez.\n";
    echo "Tedarikçiler ve XML feed'leri admin panelinden GERÇEK bilgilerle eklenir (Faz 2 UI).\n";

    Logger::info('app', 'seed.done', []);
    echo "\nSeed tamamlandı.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
