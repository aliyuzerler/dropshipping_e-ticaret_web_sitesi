<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * XML feed kaynakları (upload / url). Şifreler app katmanında AES-256-GCM ile saklanır.
 * Tam yol: database/migrations/009_create_xml_feeds.php
 */
final class CreateXmlFeedsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '009_create_xml_feeds';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE xml_feeds (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                supplier_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL,
                source_type VARCHAR(10) NOT NULL DEFAULT "upload",
                source_url VARCHAR(1000) NULL,
                auth_type VARCHAR(20) NOT NULL DEFAULT "none",
                auth_username VARCHAR(180) NULL,
                auth_password VARCHAR(500) NULL,
                format VARCHAR(20) NOT NULL DEFAULT "xml",
                encoding VARCHAR(30) NOT NULL DEFAULT "UTF-8",
                product_node VARCHAR(100) NOT NULL DEFAULT "product",
                interval_minutes INT NOT NULL DEFAULT 60,
                max_file_size_mb INT NOT NULL DEFAULT 100,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_import_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_xml_feeds_supplier_name (supplier_id, name),
                KEY idx_xml_feeds_active (is_active, source_type),
                CONSTRAINT fk_xml_feeds_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE RESTRICT
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS xml_feeds');
    }
}
