<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Sepet kalemleri. unit_price: ekleme anındaki fiyat (checkout'ta yeniden doğrulanır).
 * Tam yol: database/migrations/015_create_cart_items.php
 */
final class CreateCartItemsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '015_create_cart_items';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE cart_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                cart_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                variant_id BIGINT UNSIGNED NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_cart_items_cart (cart_id),
                CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE CASCADE,
                CONSTRAINT fk_cart_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT,
                CONSTRAINT fk_cart_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS cart_items');
    }
}
