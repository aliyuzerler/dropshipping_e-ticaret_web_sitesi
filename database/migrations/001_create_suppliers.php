<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Tedarikçiler (dropshipping kaynağı).
 * Tam yol: database/migrations/001_create_suppliers.php
 */
final class CreateSuppliersTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '001_create_suppliers';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE suppliers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                code VARCHAR(40) NOT NULL,
                contact_name VARCHAR(120) NULL,
                email VARCHAR(180) NULL,
                phone VARCHAR(40) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_suppliers_code (code),
                KEY idx_suppliers_active (is_active)
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS suppliers');
    }
}
