<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * product_supplier_data tablosu erişimi (dropshipping eşleştirme çekirdeği).
 *
 * Tam yol: app/Repositories/ProductSupplierDataRepository.php
 */
final class ProductSupplierDataRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_supplier_data';
    }

    /**
     * Tedarikçi + tedarikçi ürün kodu ile eşleştirir (kopya ürünü önleyen anahtar).
     * @return array<string,mixed>|null
     */
    public function findBySupplierSku(int $supplierId, string $supplierSku): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM product_supplier_data WHERE supplier_id = ? AND supplier_sku = ? LIMIT 1'
        );
        $stmt->execute([$supplierId, $supplierSku]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Feed'te bu aktarımda görülmeyen ürünleri bulur (pasife alma adayı).
     * @return list<array{product_id:int, status:string}>
     */
    public function staleForFeed(int $feedId, string $beforeDatetime): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT psd.product_id, p.status
             FROM product_supplier_data psd
             INNER JOIN products p ON p.id = psd.product_id
             WHERE psd.xml_feed_id = ?
               AND (psd.last_seen_at IS NULL OR psd.last_seen_at < ?)
               AND p.deleted_at IS NULL'
        );
        $stmt->execute([$feedId, $beforeDatetime]);
        /** @var list<array{product_id:int, status:string}> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Feed kapsamındaki görünmeyen ürünleri pasife alır; etkilenen satır sayısını döndürür.
     *
     * $runStart bu aktarımın başlangıç zamanı (saniye hassasiyetli). Bu aktarımda görülen
     * ürünler ($excludeProductIds) açıkça korunur — aynı saniye belirsizliği olmaz.
     * Ayrıca aynı tedarikçinin BAŞKA feed'i ürünü bu koşumda gördüyse (last_seen_at > runStart)
     * o ürün pasife alınmaz (feed'ler arası yanlış pasife alma engellenir).
     *
     * @param list<int> $excludeProductIds
     */
    public function deactivateStaleForFeed(int $feedId, string $runStart, int $supplierId, array $excludeProductIds = []): int
    {
        $base = 'UPDATE products p
                 INNER JOIN product_supplier_data psd ON psd.product_id = p.id
                 SET p.status = "passive", p.updated_at = NOW()
                 WHERE psd.xml_feed_id = ?
                   AND (psd.last_seen_at IS NULL OR psd.last_seen_at <= ?)
                   AND p.deleted_at IS NULL
                   AND p.status <> "passive"
                   AND NOT EXISTS (
                       SELECT 1 FROM product_supplier_data x
                       WHERE x.product_id = p.id AND x.supplier_id = ?
                         AND x.last_seen_at > ?
                   )';

        $chunks = array_chunk(array_values(array_unique(array_map('intval', $excludeProductIds))), 500);
        if ($chunks === []) {
            $chunks = [[]];
        }

        $affected = 0;
        foreach ($chunks as $chunk) {
            $sql = $base;
            $params = [$feedId, $runStart, $supplierId, $runStart];
            if ($chunk !== []) {
                $sql .= ' AND p.id NOT IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
                $params = [...$params, ...$chunk];
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $affected += $stmt->rowCount();
        }
        return $affected;
    }

    /**
     * Bu feed'e bağlı ürün sayısı (pasife alma önizlemesi için).
     */
    public function countForFeed(int $feedId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM product_supplier_data WHERE xml_feed_id = ?'
        );
        $stmt->execute([$feedId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * AŞAMA 3 — Bir ürünün tüm tedarikçi veri satırları (checkout snapshot'ı
     * için primary supplier tespiti + vitrinde bayatlık bilgisi).
     *
     * @return list<array<string,mixed>>
     */
    public function forProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM product_supplier_data WHERE product_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$productId]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }
}
