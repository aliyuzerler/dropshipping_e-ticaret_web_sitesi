<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * cart_items tablosu erişimi — ürün/varyant/görsel JOIN'li okumalar.
 * Fiyatlar her okumada DB'den TAZE alınır (tarayıcıdan gelen tutara güvenilmez).
 *
 * Tam yol: app/Repositories/CartItemRepository.php
 */
final class CartItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'cart_items';
    }

    private const SELECT_JOIN = '
        SELECT ci.id, ci.cart_id, ci.product_id, ci.variant_id, ci.quantity, ci.unit_price AS added_unit_price,
               p.name, p.slug, p.sku, p.status, p.stock AS product_stock, p.sale_price, p.list_price,
               p.tax_rate, p.currency, p.deleted_at,
               v.sku AS variant_sku, v.attributes AS variant_attributes, v.price AS variant_price,
               v.stock AS variant_stock, v.status AS variant_status,
               (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id
                ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        LEFT JOIN product_variants v ON v.id = ci.variant_id';

    /**
     * @return list<array<string,mixed>>
     */
    public function forCart(int $cartId): array
    {
        $stmt = $this->pdo->prepare(self::SELECT_JOIN . ' WHERE ci.cart_id = ? ORDER BY ci.id ASC');
        $stmt->execute([$cartId]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function findForCart(int $cartId, int $itemId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT_JOIN . ' WHERE ci.cart_id = ? AND ci.id = ? LIMIT 1');
        $stmt->execute([$cartId, $itemId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findByCartProductVariant(int $cartId, int $productId, ?int $variantId): ?array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT_JOIN . ' WHERE ci.cart_id = ? AND ci.product_id = ? AND ci.variant_id ' .
            ($variantId === null ? 'IS NULL' : '= ?') . ' LIMIT 1'
        );
        $stmt->execute($variantId === null ? [$cartId, $productId] : [$cartId, $productId, $variantId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function updateQuantity(int $itemId, int $quantity): bool
    {
        $stmt = $this->pdo->prepare('UPDATE cart_items SET quantity = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$quantity, $itemId]);
    }

    public function clear(int $cartId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = ?');
        $stmt->execute([$cartId]);
    }

    public function countForCart(int $cartId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE cart_id = ?');
        $stmt->execute([$cartId]);
        return (int) $stmt->fetchColumn();
    }
}
