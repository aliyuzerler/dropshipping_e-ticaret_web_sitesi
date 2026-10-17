<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Müşteri adresleri (siparişler ayrıca JSON snapshot tutar).
 * Tam yol: database/migrations/013_create_addresses.php
 */
final class CreateAddressesTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '013_create_addresses';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE addresses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(80) NULL,
                type VARCHAR(20) NOT NULL DEFAULT "shipping",
                full_name VARCHAR(160) NULL,
                country_code CHAR(2) NOT NULL DEFAULT "TR",
                city VARCHAR(80) NULL,
                district VARCHAR(80) NULL,
                postal_code VARCHAR(16) NULL,
                line1 VARCHAR(255) NOT NULL,
                line2 VARCHAR(255) NULL,
                phone VARCHAR(40) NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_addresses_customer (customer_id),
                CONSTRAINT fk_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS addresses');
    }
}
