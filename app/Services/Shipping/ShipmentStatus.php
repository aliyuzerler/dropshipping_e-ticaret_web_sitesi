<?php

declare(strict_types=1);

namespace App\Services\Shipping;

/**
 * AŞAMA 5 — Kargo gönderi durumu etiketleri.
 *
 * preparing → shipped → in_transit → delivered
 * herhangi → exception | returned
 *
 * Tam yol: app/Services/Shipping/ShipmentStatus.php
 */
final class ShipmentStatus
{
    public const PREPARING  = 'preparing';
    public const SHIPPED    = 'shipped';
    public const IN_TRANSIT = 'in_transit';
    public const DELIVERED  = 'delivered';
    public const EXCEPTION  = 'exception';
    public const RETURNED   = 'returned';

    /** @var array<string,string> */
    public const LABELS = [
        self::PREPARING  => 'Hazırlanıyor',
        self::SHIPPED    => 'Kargoya Verildi',
        self::IN_TRANSIT => 'Yolda',
        self::DELIVERED  => 'Teslim Edildi',
        self::EXCEPTION  => 'Sorunlu',
        self::RETURNED   => 'İade Yolda Döndü',
    ];

    /** @var array<string,string> */
    public const BADGES = [
        self::PREPARING  => 'secondary',
        self::SHIPPED    => 'info',
        self::IN_TRANSIT => 'primary',
        self::DELIVERED  => 'success',
        self::EXCEPTION  => 'danger',
        self::RETURNED   => 'warning',
    ];

    /** @var list<string> */
    public const ALL = [self::PREPARING, self::SHIPPED, self::IN_TRANSIT, self::DELIVERED, self::EXCEPTION, self::RETURNED];

    /** Geçerli geçiş haritası (idempotent aynı-durum hariç). @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PREPARING  => [self::SHIPPED, self::EXCEPTION, self::RETURNED],
        self::SHIPPED    => [self::IN_TRANSIT, self::DELIVERED, self::EXCEPTION, self::RETURNED],
        self::IN_TRANSIT => [self::DELIVERED, self::EXCEPTION, self::RETURNED],
        self::DELIVERED  => [self::RETURNED],
        self::EXCEPTION  => [self::SHIPPED, self::IN_TRANSIT, self::DELIVERED, self::RETURNED],
        self::RETURNED   => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return $from === $to || in_array($to, self::TRANSITIONS[$from] ?? [], true);
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
