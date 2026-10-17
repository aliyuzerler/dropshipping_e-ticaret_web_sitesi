<?php

declare(strict_types=1);

namespace App\Services\Supplier;

/**
 * AŞAMA 5 — Manuel tedarikçi adaptörü.
 *
 * Gerçek API bilgisi olmayan tedarikçiler için TEK doğru davranış: otomatik
 * gönderim YOK. Sipariş, admin panelindeki "bekleyen görev" listesinde kalır;
 * yönetici siparişi tedarikçiye (e-posta/telefon/portal) kendisi iletir ve
 * panelde manuel işaretler. Bu sınıf bilinçli olarak SAHTE başarı döndürmez —
 * supportsApi() daima false'tur, sendOrder() asla çağrılmamalıdır.
 *
 * Tam yol: app/Services/Supplier/ManualSupplierAdapter.php
 */
final class ManualSupplierAdapter implements SupplierAdapterInterface
{
    public function supportsApi(): bool
    {
        return false;
    }

    public function sendOrder(array $supplierOrder, array $items, array $context): array
    {
        // Manuel tedarikçi: otomatik gönderim yok (görev gereği sahte entegrasyon üretilmez).
        return [
            'ok'                 => false,
            'external_reference' => null,
            'message'            => 'Manuel tedarikçi — otomatik API gönderimi yapılmaz. Sipariş yönetim panelinden manuel iletilmelidir.',
            'timeout'            => false,
            'raw'                => null,
        ];
    }

    public function queryStatus(array $supplierOrder): array
    {
        return ['ok' => false, 'confirmed' => false, 'found' => false, 'message' => 'Manuel tedarikçi — durum sorgusu API üzerinden yapılamaz.', 'external_reference' => null];
    }

    public function fetchTracking(array $supplierOrder): array
    {
        return ['ok' => false, 'carrier' => null, 'tracking_number' => null, 'tracking_url' => null, 'message' => 'Manuel tedarikçi — takip bilgisi manuel girilmelidir.'];
    }
}
