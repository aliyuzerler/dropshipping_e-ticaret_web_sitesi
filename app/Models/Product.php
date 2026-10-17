<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Product entity + durum sabitleri.
 *
 * Tam yol: app/Models/Product.php
 */
final class Product
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PASSIVE = 'passive';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_XML = 'xml';

    public const STOCK_IN_STOCK = 'in_stock';
    public const STOCK_OUT_OF_STOCK = 'out_of_stock';
    public const STOCK_BACKORDER = 'backorder';

    public static function activeStatuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_PASSIVE];
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): array
    {
        return $row; // Faz 3'te zenginleşecek; Aşama 1'de ham satır temsili yeterlidir
    }
}
