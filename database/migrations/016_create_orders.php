<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Siparişler. Adres verisi JSON snapshot olarak KALICI saklanır.
 * Tam yol: database/migrations/016_create_orders.php
 */
final class CreateOrdersTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '016_create_orders';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_number VARCHAR(20) NOT NULL,
                customer_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT "pending",
                currency CHAR(3) NOT NULL DEFAULT "TRY",
                items_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                discount_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                shipping_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                tax_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                grand_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                shipping_method VARCHAR(50) NULL,
                shipping_snapshot JSON NULL,
                billing_snapshot JSON NULL,
                note TEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                placed_at DATETIME NULL,
                paid_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_orders_number (order_number),
                KEY idx_orders_customer (customer_id),
                KEY idx_orders_status_placed (status, placed_at),
                CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS orders');
    }
}
