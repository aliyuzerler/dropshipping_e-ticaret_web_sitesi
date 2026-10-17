<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * price_rules tablosu erişimi (fiyatlandırma kuralları).
 *
 * Tam yol: app/Repositories/PriceRuleRepository.php
 */
final class PriceRuleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'price_rules';
    }

    /**
     * Şu an geçerli tüm aktif kurallar (öncelik → id sırası).
     * Zaman penceresi (starts_at/ends_at) SQL'de filtrelenir.
     * @return list<array<string,mixed>>
     */
    public function activeRules(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM price_rules
             WHERE is_active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             ORDER BY priority ASC, id ASC'
        );
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Yönetim listesi (bağlı adlarla).
     * @return list<array<string,mixed>>
     */
    public function listWithNames(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, s.name AS supplier_name, c.name AS category_name, p.name AS product_name
             FROM price_rules r
             LEFT JOIN suppliers s ON s.id = r.supplier_id
             LEFT JOIN categories c ON c.id = r.category_id
             LEFT JOIN products p ON p.id = r.product_id
             ORDER BY r.priority ASC, r.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }
}
