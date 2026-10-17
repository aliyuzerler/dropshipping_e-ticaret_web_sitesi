<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Ürünler. Para: DECIMAL(15,4). Soft delete: deleted_at.
 * Tam yol: database/migrations/004_create_products.php
 */
final class CreateProductsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '004_create_products';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE products (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                category_id BIGINT UNSIGNED NULL,
                sku VARCHAR(64) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                brand VARCHAR(120) NULL,
                barcode VARCHAR(64) NULL,
                currency CHAR(3) NOT NULL DEFAULT "TRY",
                list_price DECIMAL(15,4) NULL,
                sale_price DECIMAL(15,4) NULL,
                tax_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
                price_includes_tax TINYINT(1) NOT NULL DEFAULT 1,
                stock INT NOT NULL DEFAULT 0,
                stock_status VARCHAR(20) NOT NULL DEFAULT "out_of_stock",
                status VARCHAR(20) NOT NULL DEFAULT "draft",
                source_type VARCHAR(20) NOT NULL DEFAULT "manual",
                is_featured TINYINT(1) NOT NULL DEFAULT 0,
                published_at DATETIME NULL,
                deleted_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_products_sku (sku),
                UNIQUE KEY uniq_products_slug (slug),
                KEY idx_products_category (category_id),
                KEY idx_products_status (status),
                KEY idx_products_barcode (barcode),
                FULLTEXT KEY ft_products_name_desc (name, description),
                CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS products');
    }
}
