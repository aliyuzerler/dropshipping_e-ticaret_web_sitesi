<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * XML alan eşlemeleri (feed alanı → ürün alanı dönüşümleri).
 * Tam yol: database/migrations/010_create_xml_field_mappings.php
 */
final class CreateXmlFieldMappingsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '010_create_xml_field_mappings';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE xml_field_mappings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                xml_feed_id BIGINT UNSIGNED NOT NULL,
                source_path VARCHAR(255) NOT NULL,
                target_field VARCHAR(100) NOT NULL,
                field_type VARCHAR(20) NOT NULL DEFAULT "text",
                transform VARCHAR(100) NULL,
                default_value VARCHAR(255) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_xfm_feed_source_target (xml_feed_id, source_path, target_field),
                KEY idx_xfm_feed (xml_feed_id),
                CONSTRAINT fk_xfm_feed FOREIGN KEY (xml_feed_id) REFERENCES xml_feeds (id) ON DELETE CASCADE
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS xml_field_mappings');
    }
}
