<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Tedarikçi sipariş kalemleri — maliyet snapshot'u ile dropship bildirimi.
 * Tam yol: database/migrations/020_create_supplier_order_items.php
 */
final class CreateSupplierOrderItemsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '020_create_supplier_order_items';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE supplier_order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                supplier_order_id BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NULL,
                supplier_sku VARCHAR(64) NULL,
                quantity INT NOT NULL DEFAULT 1,
                unit_cost DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                line_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_soi_order_item (order_item_id),
                KEY idx_soi_supplier_order (supplier_order_id),
                CONSTRAINT fk_soi_supplier_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_soi_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT,
                CONSTRAINT fk_soi_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS supplier_order_items');
    }
}
