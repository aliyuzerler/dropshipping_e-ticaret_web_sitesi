<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Tedarikçi siparişleri (dropshipping yönlendirme).
 * Tam yol: database/migrations/019_create_supplier_orders.php
 */
final class CreateSupplierOrdersTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '019_create_supplier_orders';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE supplier_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                supplier_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "pending",
                external_reference VARCHAR(100) NULL,
                note TEXT NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_supplier_orders_order_supplier (order_id, supplier_id),
                KEY idx_supplier_orders_supplier (supplier_id, status),
                CONSTRAINT fk_supplier_orders_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_supplier_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS supplier_orders');
    }
}
