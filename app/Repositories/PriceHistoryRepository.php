<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * product_price_history tablosu erişimi (fiyat/stok değişim geçmişi + kâr uyarıları).
 *
 * Tam yol: app/Repositories/PriceHistoryRepository.php
 */
final class PriceHistoryRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_price_history';
    }

    /**
     * @param array{
     *   product_id:int, supplier_id:?int, xml_feed_id:?int, import_log_id:?int,
     *   old_cost:?float, new_cost:?float, old_sale_price:?float, new_sale_price:?float,
     *   old_stock:?int, new_stock:?int, currency:string, profit_margin:?float, is_low_margin:bool
     * } $entry
     */
    public function addChange(array $entry): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO product_price_history
                (product_id, supplier_id, xml_feed_id, import_log_id,
                 old_cost, new_cost, old_sale_price, new_sale_price, old_stock, new_stock,
                 currency, profit_margin, is_low_margin, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $entry['product_id'],
            $entry['supplier_id'],
            $entry['xml_feed_id'],
            $entry['import_log_id'],
            $entry['old_cost'],
            $entry['new_cost'],
            $entry['old_sale_price'],
            $entry['new_sale_price'],
            $entry['old_stock'],
            $entry['new_stock'],
            $entry['currency'],
            $entry['profit_margin'],
            $entry['is_low_margin'] ? 1 : 0,
        ]);
    }

    /**
     * Son fiyat/stok güncellemeleri (yönetim ekranı).
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 100, ?bool $onlyLowMargin = null): array
    {
        $sql = 'SELECT h.*, p.name AS product_name, p.sku, s.name AS supplier_name
                FROM product_price_history h
                INNER JOIN products p ON p.id = h.product_id
                LEFT JOIN suppliers s ON s.id = h.supplier_id';
        if ($onlyLowMargin === true) {
            $sql .= ' WHERE h.is_low_margin = 1';
        }
        $sql .= ' ORDER BY h.id DESC LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Belirli aktarım log'una ait değişiklikler.
     * @return list<array<string,mixed>>
     */
    public function forImportLog(int $importLogId, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.*, p.name AS product_name, p.sku
             FROM product_price_history h
             INNER JOIN products p ON p.id = h.product_id
             WHERE h.import_log_id = ?
             ORDER BY h.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $importLogId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }
}
