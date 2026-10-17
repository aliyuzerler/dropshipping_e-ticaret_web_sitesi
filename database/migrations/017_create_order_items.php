<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Sipariş kalemleri — ürün adı/SKU/fiyat SNAPSHOT kolonları (fatura güvenliği).
 * Tam yol: database/migrations/017_create_order_items.php
 */
final class CreateOrderItemsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '017_create_order_items';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NULL,
                variant_id BIGINT UNSIGNED NULL,
                supplier_id BIGINT UNSIGNED NULL,
                product_name_snapshot VARCHAR(255) NOT NULL,
                sku_snapshot VARCHAR(64) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                tax_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
                tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT "TRY",
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_order_items_order (order_id),
                KEY idx_order_items_product (product_id),
                KEY idx_order_items_supplier (supplier_id),
                CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL,
                CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE SET NULL,
                CONSTRAINT fk_order_items_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS order_items');
    }
}
