<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Fiyat kuralları (kâr marjı, kampanya).
 * Tam yol: database/migrations/008_create_price_rules.php
 */
final class CreatePriceRulesTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '008_create_price_rules';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE price_rules (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(150) NOT NULL,
                type VARCHAR(20) NOT NULL,
                scope VARCHAR(20) NOT NULL DEFAULT "global",
                product_id BIGINT UNSIGNED NULL,
                category_id BIGINT UNSIGNED NULL,
                variant_id BIGINT UNSIGNED NULL,
                supplier_id BIGINT UNSIGNED NULL,
                value DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                min_qty INT NOT NULL DEFAULT 1,
                currency CHAR(3) NOT NULL DEFAULT "TRY",
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                priority INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_price_rules_product (product_id),
                KEY idx_price_rules_category (category_id),
                KEY idx_price_rules_supplier (supplier_id),
                KEY idx_price_rules_active (is_active, priority),
                CONSTRAINT fk_price_rules_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
                CONSTRAINT fk_price_rules_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE,
                CONSTRAINT fk_price_rules_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE CASCADE,
                CONSTRAINT fk_price_rules_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS price_rules');
    }
}
