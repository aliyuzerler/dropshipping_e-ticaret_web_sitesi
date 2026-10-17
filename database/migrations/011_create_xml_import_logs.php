<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * XML ithalat günlükleri (feed silinse bile kayıt kalır — SET NULL).
 * Tam yol: database/migrations/011_create_xml_import_logs.php
 */
final class CreateXmlImportLogsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '011_create_xml_import_logs';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE xml_import_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                xml_feed_id BIGINT UNSIGNED NULL,
                supplier_id BIGINT UNSIGNED NULL,
                file_name VARCHAR(255) NOT NULL,
                source VARCHAR(10) NOT NULL DEFAULT "upload",
                status VARCHAR(20) NOT NULL DEFAULT "running",
                total_rows INT UNSIGNED NOT NULL DEFAULT 0,
                created_rows INT UNSIGNED NOT NULL DEFAULT 0,
                updated_rows INT UNSIGNED NOT NULL DEFAULT 0,
                skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
                error_rows INT UNSIGNED NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                duration_ms INT UNSIGNED NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_xil_feed_created (xml_feed_id, created_at),
                KEY idx_xil_status (status),
                CONSTRAINT fk_xil_feed FOREIGN KEY (xml_feed_id) REFERENCES xml_feeds (id) ON DELETE SET NULL,
                CONSTRAINT fk_xil_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS xml_import_logs');
    }
}
