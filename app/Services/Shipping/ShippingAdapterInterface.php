<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/**
 * AŞAMA 5 — Kargo takip adaptörü sözleşmesi.
 *
 * Gerçek kargo firması API'si varsa bu arayüzü uygulayan bir adaptör yazılır
 * (örn. Yurtiçi/Aras/MNG REST uçları — gerçek API belgesi görülünce eklenir).
 * API yoksa ManualShippingAdapter devrededir: takip numarası admin panelinden
 * MANUEL girilir (görev gereği sahte otomatik entegrasyon üretilmez).
 *
 * Tam yol: app/Services/Shipping/ShippingAdapterInterface.php
 */
interface ShippingAdapterInterface
{
    /**
     * Takip numarasının güncel durumunu sorgular.
     *
     * @param array<string,mixed> $shipment shipments satırı
     * @return array{ok:bool, status:?string, delivered:bool, message:string}
     *         status: kargoya özel ham durum kodu (sınıf eşlemesi ShipmentService'te)
     */
    public function track(array $shipment): array;
}
