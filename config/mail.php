<?php

declare(strict_types=1);

/**
 * AŞAMA 5 — E-posta gönderim yapılandırması.
 *
 * driver=log  → e-postalar storage/logs/mail.log'a yazılır (varsayılan; güvenli test)
 * driver=smtp → gerçek SMTP üzerinden gönderim (canlıya geçiş)
 *
 * Gizli bilgiler (SMTP parolası) yalnız .env üzerinden okunur — repoya yazılmaz.
 *
 * Tam yol: config/mail.php
 */
return [
    'driver'     => env('MAIL_DRIVER', 'log'),
    'from'       => env('MAIL_FROM', 'no-reply@localhost'),
    'from_name'  => env('MAIL_FROM_NAME', 'XML E-Ticaret'),
    'smtp'       => [
        'host'     => env('SMTP_HOST', ''),
        'port'     => (int) env('SMTP_PORT', '587'),
        'secure'   => env('SMTP_SECURE', 'tls'),   // tls | ssl | none
        'username' => env('SMTP_USERNAME', ''),
        'password' => env('SMTP_PASSWORD', ''),
    ],
];
