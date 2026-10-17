<?php

declare(strict_types=1);

/**
 * Güvenlik yapılandırması.
 * Tam yol: config/security.php
 */
return [
    'session' => [
        'name'      => env('SESSION_NAME', 'xmlshop_session'),
        'secure'    => env('SESSION_SECURE', 'auto'),      // auto|true|false
        'lifetime'  => env('SESSION_LIFETIME', '2700') === '' ? 2700 : (int) env('SESSION_LIFETIME', '2700'),
    ],
    'password' => [
        'algo' => PASSWORD_BCRYPT,
        'cost' => 12,
    ],
    'admin' => [
        'max_failed_attempts' => 5,
        'lockout_minutes'     => 15,
    ],
    // APP_KEY: AES-256-GCM (feed şifreleri) — 32+ byte, base64
    'app_key' => env('APP_KEY', ''),

    // AŞAMA 6 — Taşıma güvenliği (opt-in; local/staging'de boş = kapalı, production'da önerilir)
    // 'true'/'1'/'on'/'yes' iken HTTPS olmayan istek 301 ile https://… yönlendirilir
    'force_https'   => env('APP_FORCE_HTTPS', ''),
    // virgülle ayrılmış izinli hostlar; Host/X-Forwarded-Host listede değilse 400
    'allowed_hosts' => env('APP_ALLOWED_HOSTS', ''),
];
