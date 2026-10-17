<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/**
 * AŞAMA 5 — Manuel kargo adaptörü (varsayılan).
 *
 * Gerçek kargo API'si bağlanmadıysa devrededir: sorgulama YAPMAZ, takip
 * numarası admin panelinden manuel girilir. (Görev gereği sahte otomatik
 * entegrasyon üretilmez.)
 *
 * Tam yol: app/Services/Shipping/ManualShippingAdapter.php
 */
final class ManualShippingAdapter implements ShippingAdapterInterface
{
    public function track(array $shipment): array
    {
        return [
            'ok'        => false,
            'status'    => null,
            'delivered' => false,
            'message'   => 'Kargo firması API entegrasyonu yok — takip bilgisi manuel güncellenir.',
        ];
    }
}
