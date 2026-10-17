<?php

declare(strict_types=1);

namespace App\Services\Supplier;

/**
 * AŞAMA 5 — Tedarikçi entegrasyon adaptörü sözleşmesi.
 *
 * Desteklenen yetenekler (görev tanımı):
 *  1) Manuel sipariş işleme       → supportsApi() = false
 *  2) Tedarikçi API'siyle gönderim → sendOrder()
 *  3) Sipariş durumu sorgulama     → queryStatus()
 *  4) Kargo takip bilgisi alma     → fetchTracking()
 *
 * ÖNEMLİ (görev gereği): XML kaynağı sipariş endpoint'i sağlamaz. Gerçek API
 * bilgisi OLAYLANmayan tedarikçi için SAHTE otomatik entegrasyon üretilMEZ;
 * o tedarikçiler ManualSupplierAdapter ile yönetilir (admin panelinde bekleyen
 * görev + manuel durum güncelleme).
 *
 * Tam yol: app/Services/Supplier/SupplierAdapterInterface.php
 */
interface SupplierAdapterInterface
{
    /** Adaptörün tedarikçi API'siyle gerçek gönderim yapabildiği mi? */
    public function supportsApi(): bool;

    /**
     * Tedarikçi API'sine sipariş gönderir (idempotency anahtarı = dahili referans).
     *
     * @param array<string,mixed>  $supplierOrder supplier_orders satırı
     * @param list<array<string,mixed>> $items    supplier_order_items satırları
     * @param array<string,mixed>  $context       ana sipariş + teslimat snapshot'ı
     * @return array{ok:bool, external_reference:?string, message:string, timeout:bool, raw:?array<string,mixed>}
     */
    public function sendOrder(array $supplierOrder, array $items, array $context): array;

    /**
     * Tedarikçideki sipariş durumunu sorgular (zaman aşımı sonrası TEK güvenli yol).
     *
     * @return array{ok:bool, confirmed:bool, found:bool, message:string, external_reference:?string}
     *         found=false → tedarikçide kayıt yok (manuel inceleme gerekir)
     */
    public function queryStatus(array $supplierOrder): array;

    /**
     * Tedarikçiden kargo takip bilgisi alır.
     *
     * @return array{ok:bool, carrier:?string, tracking_number:?string, tracking_url:?string, message:string}
     */
    public function fetchTracking(array $supplierOrder): array;
}
