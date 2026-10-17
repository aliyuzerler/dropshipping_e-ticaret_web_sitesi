<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Ürün görselleri. UNIQUE(product_id, checksum) ile kaynak URL tekrarları engellenir.
 * Tam yol: database/migrations/006_create_product_images.php
 */
final class CreateProductImagesTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '006_create_product_images';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE product_images (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                path VARCHAR(500) NOT NULL,
                source_url VARCHAR(1000) NULL,
                checksum CHAR(64) NOT NULL,
                is_primary TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_product_images_product_checksum (product_id, checksum),
                KEY idx_product_images_product (product_id),
                CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS product_images');
    }
}
