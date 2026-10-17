<?php

declare(strict_types=1);

/**
 * AŞAMA 3 — Mağaza demo verisi: php database/seed_store.php
 *
 * DİKKAT (örnek veri beyanı): Bu betik VİTRİN DEMOSU için sentetik veri yazar.
 * Gerçek tedarikçi XML'leri admin panelinden yüklendiğinde bu demo veriler
 * gerçek verilerle idempotent biçimde güncellenir (supplier+SKU eşlemesi).
 *
 * Yapılanlar:
 *  1) Mağaza ayarları (settings — yalnız eksik anahtarlar yazılır)
 *  2) Demo kuponlar
 *  3) Kategori ağacı: "Kırtasiye" kökü + XML'den gelen alt kategoriler
 *  4) XML'den senkronlanan demo ürünler vitrine açılır (active) + öne çıkan seçimi
 *  5) Yerel SVG görsel üretimi (dış URL yerine deterministik placeholder)
 *  6) Demo müşteri hesabı (yalnız sandbox: musteri@example.test / Musteri!2026)
 *  7) Bayat stok demosu: bir ürünün last_seen_at değeri 72 saat geriye alınır
 *
 * Tam yol: database/seed_store.php
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

use App\Core\Database;
use App\Services\Auth\CustomerAuthService;
use App\Services\Store\SettingsService;

try {
    $pdo = Database::pdo();

    /* --- 1) Mağaza ayarları (yalnız eksikler) --- */
    $settings = new SettingsService();
    $inserted = (new App\Repositories\SettingRepository())->seedDefaults(SettingsService::DEFAULTS);
    echo "Ayarlar: {$inserted} yeni anahtar eklendi (toplam " . count(SettingsService::DEFAULTS) . " varsayılan).\n";

    /* --- 2) Demo kuponlar --- */
    $coupons = [
        // [code, description, type, value, min_total, max_discount, usage_limit]
        ['HOSGELDIN10', 'İlk siparişe %10 indirim (min 200 ₺)', 'percent', 10.00, 200.00, 150.00, 1000],
        ['KARGOBEDAVA', '49,90 ₺ sabit kargo indirimi (min 300 ₺)', 'fixed', 49.90, 300.00, null, null],
    ];
    $stmtCoupon = $pdo->prepare(
        'INSERT IGNORE INTO coupons (code, description, type, value, min_order_total, max_discount, usage_limit, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
    );
    foreach ($coupons as [$code, $desc, $type, $value, $min, $max, $limit]) {
        $stmtCoupon->execute([$code, $desc, $type, $value, $min, $max, $limit]);
        echo $stmtCoupon->rowCount() > 0 ? "Kupon eklendi: $code\n" : "Kupon zaten var: $code\n";
    }

    /* --- 3) Kategori ağacı --- */
    $stmtCat = $pdo->prepare('SELECT id FROM categories WHERE slug = ? LIMIT 1');
    $stmtCat->execute(['kirtasiye']);
    $parentId = $stmtCat->fetchColumn();
    if ($parentId === false) {
        $pdo->prepare('INSERT INTO categories (name, slug, is_active, sort_order, created_at, updated_at) VALUES ("Kırtasiye", "kirtasiye", 1, 0, NOW(), NOW())')
            ->execute();
        $parentId = (int) $pdo->lastInsertId();
        echo "Kategori eklendi: Kırtasiye (kök)\n";
    } else {
        $parentId = (int) $parentId;
    }

    $children = ['Kalemler', 'Defterler', 'Dosyalar', 'Kağıt Ürünleri', 'Ofis Gereçleri', 'Okul Ürünleri', 'Makaslar'];
    $stmtChild = $pdo->prepare('UPDATE categories SET parent_id = ? WHERE name = ? AND parent_id IS NULL');
    foreach ($children as $name) {
        $stmtChild->execute([$parentId, $name]);
    }
    echo "Kırtasiye alt kategorileri bağlandı (" . count($children) . ").\n";

    /* --- 4) Demo ürünleri vitrine aç --- */
    $featuredSkus = ['OKT-TK-1001', 'OKT-TK-1004', 'OKT-TK-1007', 'OKT-TK-1008'];
    $pdo->exec(
        "UPDATE products
         SET status = 'active',
             stock_status = IF(stock > 0, 'in_stock', 'out_of_stock'),
             published_at = COALESCE(published_at, NOW()),
             updated_at = NOW()
         WHERE deleted_at IS NULL"
    );
    foreach ($featuredSkus as $sku) {
        $pdo->prepare("UPDATE products SET is_featured = 1 WHERE sku = ?")->execute([$sku]);
    }
    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND deleted_at IS NULL")->fetchColumn();
    echo "Vitrin ürünleri: {$activeCount} aktif (" . count($featuredSkus) . " tanesi öne çıkan).\n";

    /* --- 5) Yerel SVG görseller --- */
    $imgDir = BASE_PATH . '/public/assets/img/products';
    if (!is_dir($imgDir)) {
        mkdir($imgDir, 0775, true);
    }

    $palette = [
        'Kalemler'        => ['#0d9488', '#ccfbf1'],
        'Defterler'       => ['#ca8a04', '#fef9c3'],
        'Dosyalar'        => ['#dc2626', '#fee2e2'],
        'Kağıt Ürünleri'  => ['#7c3aed', '#ede9fe'],
        'Ofis Gereçleri'  => ['#047857', '#d1fae5'],
        'Okul Ürünleri'   => ['#ea580c', '#ffedd5'],
        'Makaslar'        => ['#be185d', '#fce7f3'],
        'Kırtasiye'       => ['#334155', '#e2e8f0'],
    ];
    $fallback = ['#047857', '#ecfdf5'];

    $products = $pdo->query(
        'SELECT p.id, p.name, p.brand, c.name AS category_name
         FROM products p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.deleted_at IS NULL'
    )->fetchAll();

    $stmtDelImg = $pdo->prepare('DELETE FROM product_images WHERE product_id = ?');
    $stmtInsImg = $pdo->prepare(
        'INSERT INTO product_images (product_id, path, source_url, checksum, is_primary, sort_order, created_at, updated_at)
         VALUES (?, ?, NULL, ?, ?, 0, NOW(), NOW())'
    );

    $svgCount = 0;
    foreach ($products as $p) {
        $pid = (int) $p['id'];
        $colors = $palette[(string) $p['category_name']] ?? $fallback;

        // Ürün adını satırlara böl (maks 3 satır, ~20 karakter)
        $words = preg_split('/\s+/u', trim((string) $p['name'])) ?: [];
        $lines = [''];
        foreach ($words as $word) {
            if (mb_strlen($lines[count($lines) - 1] . ' ' . $word, 'UTF-8') > 20 && $lines[count($lines) - 1] !== '') {
                if (count($lines) >= 3) { break; }
                $lines[] = $word;
            } else {
                $lines[count($lines) - 1] = trim($lines[count($lines) - 1] . ' ' . $word);
            }
        }
        $textSvg = '';
        $lineY = 250;
        foreach ($lines as $line) {
            $escaped = htmlspecialchars($line, ENT_XML1, 'UTF-8');
            $textSvg .= '<text x="200" y="' . $lineY . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="26" font-weight="bold" fill="#1f2937">' . $escaped . '</text>';
            $lineY += 34;
        }

        $brand = htmlspecialchars((string) ($p['brand'] ?? ''), ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400" role="img" aria-label="Ürün görseli">'
            . '<rect width="400" height="400" fill="' . $colors[1] . '"/>'
            . '<circle cx="200" cy="120" r="70" fill="' . $colors[0] . '" opacity="0.15"/>'
            . '<text x="200" y="145" text-anchor="middle" font-size="64">✏️</text>'
            . $textSvg
            . '<text x="200" y="355" text-anchor="middle" font-family="Arial, sans-serif" font-size="15" fill="#6b7280">' . $brand . '</text>'
            . '</svg>';

        $file = $imgDir . '/p' . $pid . '.svg';
        file_put_contents($file, $svg);
        $checksum = hash('sha256', $svg);

        // Demo: dış URL'li eski görselleri yerel placeholder ile değiştir
        $stmtDelImg->execute([$pid]);
        $stmtInsImg->execute([$pid, '/assets/img/products/p' . $pid . '.svg', $checksum, 1]);
        $svgCount++;
    }
    echo "Yerel SVG görsel üretildi: {$svgCount} ürün (public/assets/img/products/).\n";

    /* --- 6) Demo müşteri (yalnız sandbox) --- */
    $demoEmail = 'musteri@example.test';
    $stmtCust = $pdo->prepare('SELECT id FROM customers WHERE email = ? LIMIT 1');
    $stmtCust->execute([$demoEmail]);
    if ($stmtCust->fetchColumn() === false) {
        $pdo->prepare(
            'INSERT INTO customers (first_name, last_name, email, phone, password_hash, status, email_verified_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, "active", NOW(), NOW(), NOW())'
        )->execute([
            'Demo', 'Müşteri', $demoEmail, '+90 555 000 00 00',
            CustomerAuthService::hashPassword('Musteri!2026'),
        ]);
        echo "Demo müşteri oluşturuldu: $demoEmail / Musteri!2026 (yalnız sandbox — canlıya taşınmaz)\n";
    } else {
        echo "Demo müşteri zaten var: $demoEmail\n";
    }

    /* --- 7) Bayat stok demosu: OKT-TK-1004 → 72 saat geriye --- */
    $pdo->prepare(
        "UPDATE product_supplier_data
         SET last_seen_at = DATE_SUB(NOW(), INTERVAL 72 HOUR)
         WHERE product_id = (SELECT id FROM products WHERE sku = 'OKT-TK-1004' LIMIT 1)"
    )->execute();
    echo "Bayat stok demosu: OKT-TK-1004 last_seen_at → -72 saat (kural: " . $settings->staleStockHours() . " saat, davranış: " . $settings->staleStockAction() . ").\n";

    echo "\nMağaza demo verisi tamamlandı.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'HATA: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
