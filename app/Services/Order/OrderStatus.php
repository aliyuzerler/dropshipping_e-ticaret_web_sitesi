<?php

declare(strict_types=1);

namespace App\Services\Order;

/**
 * AŞAMA 4 — Sipariş durum makinesi.
 *
 * Durumlar (görev tanımına uygun):
 *   pending_payment → paid → processing → supplier_pending → shipped → delivered
 *   pending_payment → payment_failed (yeniden denenebilir)
 *   paid/processing/supplier_pending → refund_pending → refunded (tam iade)
 *   kısmi iade → önceki işleme durumuna geri dönüş (restore_status)
 *   iptal: yalnızca tanımlı geçişlerle (kargo çıkmadan)
 *
 * Tüm geçişler can() ile doğrulanır; her değişiklik OrderService tarafından
 * audit_logs'a yazılır. Tanımsız geçiş REDDEDİLİR.
 *
 * Tam yol: app/Services/Order/OrderStatus.php
 */
final class OrderStatus
{
    public const PENDING_PAYMENT = 'pending_payment';
    public const PAID            = 'paid';
    public const PROCESSING      = 'processing';
    public const SUPPLIER_PENDING = 'supplier_pending';
    public const SHIPPED         = 'shipped';
    public const DELIVERED       = 'delivered';
    public const CANCELLED       = 'cancelled';
    public const REFUND_PENDING  = 'refund_pending';
    public const REFUNDED        = 'refunded';
    public const PAYMENT_FAILED  = 'payment_failed';

    /** @var array<string,string> */
    public const LABELS = [
        self::PENDING_PAYMENT  => 'Ödeme Bekliyor',
        self::PAID             => 'Ödendi',
        self::PROCESSING       => 'Hazırlanıyor',
        self::SUPPLIER_PENDING => 'Tedarikçi Bekliyor',
        self::SHIPPED          => 'Kargoda',
        self::DELIVERED        => 'Teslim Edildi',
        self::CANCELLED        => 'İptal Edildi',
        self::REFUND_PENDING   => 'İade Sürecinde',
        self::REFUNDED         => 'İade Edildi',
        self::PAYMENT_FAILED   => 'Ödeme Başarısız',
    ];

    /** @var array<string,string> Bootstrap badge renk sınıfları */
    public const BADGES = [
        self::PENDING_PAYMENT  => 'warning',
        self::PAID             => 'success',
        self::PROCESSING       => 'info',
        self::SUPPLIER_PENDING => 'secondary',
        self::SHIPPED          => 'primary',
        self::DELIVERED        => 'success',
        self::CANCELLED        => 'danger',
        self::REFUND_PENDING   => 'warning',
        self::REFUNDED         => 'dark',
        self::PAYMENT_FAILED   => 'danger',
    ];

    /**
     * İzinli geçiş haritası. Anahtar: kaynak durum, değer: hedef durumlar.
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::PENDING_PAYMENT  => [self::PAID, self::PAYMENT_FAILED, self::CANCELLED],
        self::PAYMENT_FAILED   => [self::PENDING_PAYMENT, self::PAID, self::CANCELLED],
        self::PAID             => [self::PROCESSING, self::SUPPLIER_PENDING, self::REFUND_PENDING, self::CANCELLED],
        self::PROCESSING       => [self::SUPPLIER_PENDING, self::SHIPPED, self::REFUND_PENDING, self::CANCELLED],
        self::SUPPLIER_PENDING => [self::PROCESSING, self::SHIPPED, self::REFUND_PENDING],
        self::SHIPPED          => [self::DELIVERED, self::REFUND_PENDING],
        self::DELIVERED        => [self::REFUND_PENDING],
        self::REFUND_PENDING   => [self::REFUNDED, self::PAID, self::PROCESSING, self::SUPPLIER_PENDING, self::SHIPPED, self::DELIVERED],
        self::REFUNDED         => [],
        self::CANCELLED        => [],
    ];

    /** Geçiş izinli mi? */
    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Bir durumdan gidilebilecek tüm durumlar. @return list<string> */
    public static function allowedTargets(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function badge(string $status): string
    {
        return self::BADGES[$status] ?? 'secondary';
    }

    /** Ödeme başlatılabilecek (veya yeniden başlatılabilecek) durumlar. @return list<string> */
    public static function payable(): array
    {
        return [self::PENDING_PAYMENT, self::PAYMENT_FAILED];
    }

    /** Müşterinin iade talebi açabildiği durumlar. @return list<string> */
    public static function refundRequestable(): array
    {
        return [self::PAID, self::PROCESSING, self::SUPPLIER_PENDING, self::SHIPPED, self::DELIVERED];
    }

    /** Ödeme sonrası admin iptalinin mümkün olduğu durumlar (kargo öncesi). @return list<string> */
    public static function cancellableAfterPayment(): array
    {
        return [self::PAID, self::PROCESSING, self::SUPPLIER_PENDING];
    }
}
