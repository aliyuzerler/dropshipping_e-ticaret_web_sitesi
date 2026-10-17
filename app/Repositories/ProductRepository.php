<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * products tablosu erişimi.
 *
 * Tam yol: app/Repositories/ProductRepository.php
 */
final class ProductRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'products';
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findBySku(string $sku): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE sku = ? LIMIT 1');
        $stmt->execute([$sku]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE slug = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function activeProducts(int $limit = 24, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM products WHERE status = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, \App\Models\Product::STATUS_ACTIVE);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE products SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        return $stmt->execute([$id]);
    }
}
