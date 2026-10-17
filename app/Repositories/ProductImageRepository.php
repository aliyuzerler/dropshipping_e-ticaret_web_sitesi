<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * product_images tablosu erişimi (vitrin okumaları).
 *
 * Tam yol: app/Repositories/ProductImageRepository.php
 */
final class ProductImageRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_images';
    }

    /** @return list<array<string,mixed>> */
    public function forProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, product_id, path, source_url, is_primary, sort_order
             FROM product_images WHERE product_id = ?
             ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $stmt->execute([$productId]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Birden çok ürün için ilk görsel haritası (listing tek sorgu).
     * @param list<int> $productIds
     * @return array<int,string> productId => path
     */
    public function primaryForProducts(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT product_id, path FROM product_images
             WHERE product_id IN ($placeholders)
             ORDER BY is_primary DESC, sort_order ASC, id ASC"
        );
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($map[$pid])) {
                $map[$pid] = (string) $row['path'];
            }
        }
        return $map;
    }
}
