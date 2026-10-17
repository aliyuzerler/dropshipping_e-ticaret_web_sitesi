<?php

declare(strict_types=1);

/**
 * php -S router dosyası (yalnız yerel geliştirme):
 *   php -S localhost:8000 -t public public/router.php
 * Statik dosyalar doğrudan sunulur, diğer istekler front controller'a gider.
 *
 * Tam yol: public/router.php
 */
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false; // statik dosyayı gömülü sunucu servis etsin
    }
}

require __DIR__ . '/index.php';
