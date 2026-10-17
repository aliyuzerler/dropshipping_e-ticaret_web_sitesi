<?php

declare(strict_types=1);

/**
 * AŞAMA 4 — Ödeme (iyzico Checkout Form) yapılandırması.
 *
 * GİZLİ ANAHTAR: yalnızca .env üzerinden okunur, ASLA frontend'e gönderilmez,
 * ASLA repoya yazılmaz. mod=mock → gerçek iyzico çağrısı yapılmaz (test/simülasyon).
 *
 * Tam yol: config/payment.php
 */
return [
    // mock | sandbox | live
    'mode'                  => env('IYZICO_MODE', 'mock'),
    'api_key'               => env('IYZICO_API_KEY', ''),
    'secret_key'            => env('IYZICO_SECRET_KEY', ''),
    'base_url'              => env('IYZICO_BASE_URL', 'https://sandbox-api.iyzipay.com'),
    'timeout'               => (int) env('IYZICO_TIMEOUT', '20'),
    'locale'                => 'tr',
    'currency'              => 'TRY',
    'enabled_installments'  => [1, 2, 3, 6, 9, 12],
    // iyzico buyer.identityNumber zorunlu alan — müşteride TCKN yoksa sandığa
    // geçerli biçimli yedek değer gönderilir (canlıya geçişte gerçek TCKN toplanmalı).
    'identity_fallback'     => '11111111111',
    // pending_payment siparişlerin otomatik iptal süresi (dk)
    'order_expire_minutes'  => (int) env('ORDER_EXPIRE_MINUTES', '120'),
    // iyzico API uçları (resmî dokümantasyon: docs.iyzico.com)
    'endpoints'             => [
        'checkout_initialize' => '/payment/iyzipos/checkoutform/initialize/auth/ecom',
        'checkout_retrieve'   => '/payment/iyzipos/checkoutform/auth/ecom/detail/',
        'cancel'              => '/payment/cancel',
        'refund'              => '/payment/refund',
    ],
];
