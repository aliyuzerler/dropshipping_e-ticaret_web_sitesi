<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/**
 * AŞAMA 5 — Kargo adaptörü üreticisi.
 *
 * Gerçek kargo API'si ayarlanana kadar (settings: shipping_adapter) daima
 * ManualShippingAdapter döner. Gerçek API ekleneceğinde:
 *   1) ShippingAdapterInterface uygulayan sınıf yazılır (yalnız gerçek belgeyle),
 *   2) bu fabrikaya kayıt edilir,
 *   3) mağaza ayarlarından seçilir.
 *
 * Tam yol: app/Services/Shipping/ShippingAdapterFactory.php
 */
final class ShippingAdapterFactory
{
    /** @var array<string, ShippingAdapterInterface> */
    private static array $registry = [];

    /** Gerçek kargo API adaptörünün kaydı (gelecek entegrasyon için). */
    public static function register(string $key, ShippingAdapterInterface $adapter): void
    {
        self::$registry[$key] = $adapter;
    }

    public static function resolve(): ShippingAdapterInterface
    {
        $key = (string) \App\Core\Config::get('shipping.adapter', 'manual');
        $adapter = self::$registry[$key] ?? null;
        if ($adapter !== null) {
            return $adapter;
        }
        return new ManualShippingAdapter();
    }
}
