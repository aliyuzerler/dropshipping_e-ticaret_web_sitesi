<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Ürün varyantları (beden/renk vb.).
 * Tam yol: database/migrations/005_create_product_variants.php
 */
final class CreateProductVariantsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '005_create_product_variants';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE product_variants (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                sku VARCHAR(64) NOT NULL,
                barcode VARCHAR(64) NULL,
                attributes JSON NULL,
                price DECIMAL(15,4) NULL,
                stock INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT "active",
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_variants_sku (sku),
                KEY idx_variants_product (product_id),
                CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS product_variants');
    }
}
