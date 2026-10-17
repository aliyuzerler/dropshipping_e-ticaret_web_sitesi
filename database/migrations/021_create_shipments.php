<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Kargo gönderileri (tedarikçi siparişi başına).
 * Tam yol: database/migrations/021_create_shipments.php
 */
final class CreateShipmentsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '021_create_shipments';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE shipments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                supplier_order_id BIGINT UNSIGNED NOT NULL,
                carrier VARCHAR(80) NULL,
                tracking_number VARCHAR(100) NULL,
                tracking_url VARCHAR(500) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "preparing",
                shipped_at DATETIME NULL,
                delivered_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_shipments_supplier_order (supplier_order_id),
                KEY idx_shipments_tracking (tracking_number),
                CONSTRAINT fk_shipments_supplier_order FOREIGN KEY (supplier_order_id) REFERENCES supplier_orders (id) ON DELETE CASCADE
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS shipments');
    }
}
