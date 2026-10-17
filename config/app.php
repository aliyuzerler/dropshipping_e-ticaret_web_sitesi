<?php

declare(strict_types=1);

/**
 * Uygulama yapılandırması.
 * Tam yol: config/app.php
 */
return [
    'env'       => env('APP_ENV', 'production'),
    // Hata detaylarını ekrana basma anahtarı — üretimde MUTLAKA 0/boş (doctor denetler)
    'debug'     => env('APP_DEBUG', '0'),
    'name'      => 'XML E-Ticaret',
    'url'       => env('APP_URL', 'http://localhost:8000'),
    'timezone'  => env('APP_TIMEZONE', 'UTC'),
    'log_level' => env('LOG_LEVEL', 'info'),
];
