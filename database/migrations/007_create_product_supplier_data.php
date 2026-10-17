<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Tedarikçi başına ürün verisi (stoksuz satışın çekirdek tablosu).
 * Tam yol: database/migrations/007_create_product_supplier_data.php
 */
final class CreateProductSupplierDataTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '007_create_product_supplier_data';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE product_supplier_data (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                supplier_id BIGINT UNSIGNED NOT NULL,
                supplier_sku VARCHAR(64) NULL,
                supplier_price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                supplier_currency CHAR(3) NOT NULL DEFAULT "TRY",
                stock INT NULL,
                stock_updated_at DATETIME NULL,
                lead_time_days INT NULL,
                supplier_status VARCHAR(30) NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_psd_product_supplier (product_id, supplier_id),
                KEY idx_psd_supplier (supplier_id),
                KEY idx_psd_supplier_sku (supplier_id, supplier_sku),
                CONSTRAINT fk_psd_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
                CONSTRAINT fk_psd_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS product_supplier_data');
    }
}
