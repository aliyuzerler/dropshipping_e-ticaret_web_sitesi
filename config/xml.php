<?php

declare(strict_types=1);

/**
 * XML import yapılandırması (Aşama 1 + Aşama 2).
 * Tam yol: config/xml.php
 */
$int = static fn (string $key, int $default): int => env($key, (string) $default) === '' ? $default : (int) env($key, (string) $default);

return [
    'upload' => [
        'max_mb'          => $int('XML_UPLOAD_MAX_MB', 100),
        'allowed_ext'     => ['xml'],
        'allowed_mime'    => ['text/xml', 'application/xml'],
    ],
    'fetch' => [
        'timeout_seconds' => $int('XML_FETCH_TIMEOUT', 30),
        'max_redirects'   => $int('XML_FETCH_MAX_REDIRECT', 3),
        'allowed_ports'   => [80, 443],
        'allowed_schemes' => ['http', 'https'],
        'user_agent'      => 'XmlFeedBot/1.0 (+https://example.local/bot)',
    ],
    'limits' => [
        'max_memory_mb' => $int('XML_MAX_MEMORY_MB', 256),
    ],
    'sync' => [
        // Akış işlemesini parçalı idempotent bloklar hâlinde işler
        'batch_size'      => max(1, $int('XML_SYNC_BATCH', 50)),
        // Önizlemede gösterilecek maksimum satır
        'preview_rows'    => max(1, $int('XML_PREVIEW_ROWS', 20)),
        // Satış fiyatlarının baz alındığı hedef para birimi
        'target_currency' => 'TRY',
        // XML'deki para birimi → hedef para birimi çevirim katsayıları
        // (bilinmeyen para birimli satır hata olarak işaretlenir, sessizce kabul edilmez)
        'currency_rates'  => [
            'TRY' => 1.0,
            'USD' => (float) (env('XML_RATE_USD', '34.0') !== '' ? env('XML_RATE_USD', '34.0') : 34.0),
            'EUR' => (float) (env('XML_RATE_EUR', '37.0') !== '' ? env('XML_RATE_EUR', '37.0') : 37.0),
            'GBP' => (float) (env('XML_RATE_GBP', '43.0') !== '' ? env('XML_RATE_GBP', '43.0') : 43.0),
        ],
    ],
    'pricing' => [
        // true ise hesaplanan satış fiyatı KDV dahil gösterilir (sale = net * (1 + kdv/100))
        'apply_tax_to_sale'   => in_array(strtolower((string) env('PRICING_TAX_IN_SALE', '0')), ['1', 'true', 'yes', 'on'], true),
        // Kârlılık eşiği uyarısı: kâr marjı (%) bu değerin altına düşerse uyarı işaretlenir
        'profit_warn_percent' => (float) (env('PRICING_PROFIT_WARN', '10') !== '' ? env('PRICING_PROFIT_WARN', '10') : 10),
        // Varsayılan yuvarlama adımı (fiyat kuralı tanımlanmadıysa uygulanmaz; null = yuvarlama yok)
        'default_round_step'  => null,
    ],
];
