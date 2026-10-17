<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * product_variants tablosu erişimi (vitrin okumaları).
 *
 * Tam yol: app/Repositories/ProductVariantRepository.php
 */
final class ProductVariantRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_variants';
    }

    /** @return list<array<string,mixed>> */
    public function forProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, product_id, sku, barcode, attributes, price, stock, status
             FROM product_variants WHERE product_id = ? AND status = 'active'
             ORDER BY id ASC"
        );
        $stmt->execute([$productId]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function findActive(int $variantId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM product_variants WHERE id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$variantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Fiyat: varyant fiyatı yoksa ürün fiyatı devralınır (vitrende gösterim birliği). */
    public static function effectivePrice(?string $variantPrice, ?string $productSalePrice): float
    {
        return (float) ($variantPrice !== null && $variantPrice !== '' ? $variantPrice : $productSalePrice);
    }
}
