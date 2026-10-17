<?php

declare(strict_types=1);

/**
 * Veritabanı yapılandırması (MySQL 8+, PDO).
 * Tam yol: config/database.php
 */
return [
    'host'     => env('DB_HOST', '127.0.0.1'),
    'port'     => env('DB_PORT', '3306') === '' ? 3306 : (int) env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'xml_ecommerce'),
    'charset'  => env('DB_CHARSET', 'utf8mb4'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
];
