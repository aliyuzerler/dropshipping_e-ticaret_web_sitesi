<?php

declare(strict_types=1);

namespace App\Services\Supplier;

/**
 * AŞAMA 5 — Tedarikçi siparişi durum etiketleri.
 *
 * Yaşam döngüsü:
 *   pending  → (API başarılı) sent → confirmed
 *   pending  → (API hatası) error → (admin yeniden dener) pending
 *   sending  → (zaman aşımı / süreç ölümü) unknown → (durum sorgusu) sent|confirmed|error
 *   pending  → (manuel işaretleme) sent (sent_by=manual)
 *   pending/sending → cancelled (ana sipariş iptali, kargo öncesi)
 *
 * "sending" TRANSİT bir durumdur: HTTP çağrısı sürerken satır bu durumdadır.
 * Çökme/zaman aşımı sonrası bu durumda kalan kayıtlar ASLA otomatik yeniden
 * gönderilmez — önce tedarikçiye durum sorulur (supplier:query), cevap
 * alınamazsa manuel incelemeye kalır. Bu, "yan tarafta sipariş oluştu mu?"
 * belirsizliğinde mükerrer gönderimi engeller.
 *
 * Tam yol: app/Services/Supplier/SupplierOrderStatus.php
 */
final class SupplierOrderStatus
{
    public const PENDING   = 'pending';
    public const SENDING   = 'sending';
    public const SENT      = 'sent';
    public const CONFIRMED = 'confirmed';
    public const ERROR     = 'error';
    public const UNKNOWN   = 'unknown';
    public const CANCELLED = 'cancelled';

    /** @var array<string,string> */
    public const LABELS = [
        self::PENDING   => 'Tedarikçiye Gönderilecek',
        self::SENDING   => 'Gönderiliyor…',
        self::SENT      => 'Tedarikçiye Gönderildi',
        self::CONFIRMED => 'Tedarikçi Onayladı',
        self::ERROR     => 'Gönderim Hatası',
        self::UNKNOWN   => 'Durum Belirsiz (Zaman Aşımı)',
        self::CANCELLED => 'İptal Edildi',
    ];

    /** @var array<string,string> */
    public const BADGES = [
        self::PENDING   => 'warning',
        self::SENDING   => 'info',
        self::SENT      => 'primary',
        self::CONFIRMED => 'success',
        self::ERROR     => 'danger',
        self::UNKNOWN   => 'dark',
        self::CANCELLED => 'secondary',
    ];

    /** Ödeme doğrulanmış ana sipariş durumları — tedarikçiye gönderim yalnız bunlarda yapılır. */
    public const PAYMENT_VERIFIED_ORDER_STATUSES = ['paid', 'processing', 'supplier_pending'];

    /** Gönderim bekleyen (işlenebilir) tedarikçi siparişi durumları. @return list<string> */
    public static function actionable(): array
    {
        return [self::PENDING];
    }

    /** İletim tamamlanmış sayılan durumlar. @return list<string> */
    public static function deliveredToSupplier(): array
    {
        return [self::SENT, self::CONFIRMED];
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function badge(string $status): string
    {
        return self::BADGES[$status] ?? 'secondary';
    }
}
