<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Kategoriler (self-referencing ağaç).
 * Tam yol: database/migrations/003_create_categories.php
 */
final class CreateCategoriesTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '003_create_categories';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE categories (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_id BIGINT UNSIGNED NULL,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(180) NOT NULL,
                xml_category_code VARCHAR(120) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_categories_slug (slug),
                KEY idx_categories_parent (parent_id),
                KEY idx_categories_xml_code (xml_category_code),
                CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS categories');
    }
}
