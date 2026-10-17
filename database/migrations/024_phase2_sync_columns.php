<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Core\Database;
use App\Database\MigrationInterface;
use PDO;
use Throwable;

/**
 * AŞAMA 2 — XML aktarım motoru şema genişletmesi:
 *  - xml_feeds            : senkron davranış kolonları (sku_prefix, güncelleme kuralları,
 *                           deactivate_missing, auto_publish, last_success_sync_at, last_file_path)
 *  - xml_import_logs      : deactivated_rows / warnings / file_path
 *  - product_supplier_data: feed bağlantısı + last_seen_at + alan bazında koruma bayrakları
 *  - product_price_history: fiyat/stok değişim geçmişi + kârlılık eşiği uyarısı
 *
 * Her ALTER information_schema üzerinden koşullu üretilir; migration tekrar
 * çalıştırıldığında mevcut kolonlar için SQL üretmez (idempotent).
 *
 * Tam yol: database/migrations/024_phase2_sync_columns.php
 */
final class Phase2SyncColumns implements MigrationInterface
{
    public function name(): string
    {
        return '024_phase2_sync_columns';
    }

    public function up(): array
    {
        // Not: ALTER'lar çalışma anındaki şemaya göre üretildiği için PDO burada güvenle alınır.
        $pdo = Database::pdo();
        $sql = [];

        /* --- xml_feeds --- */
        $sql = [
            ...$sql,
            ...$this->addColumn($pdo, 'xml_feeds', 'sku_prefix', 'VARCHAR(20) NULL AFTER product_node'),
            ...$this->addColumn($pdo, 'xml_feeds', 'default_category_id', 'BIGINT UNSIGNED NULL AFTER sku_prefix'),
            ...$this->addColumn($pdo, 'xml_feeds', 'update_description', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER max_file_size_mb'),
            ...$this->addColumn($pdo, 'xml_feeds', 'update_images', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER update_description'),
            ...$this->addColumn($pdo, 'xml_feeds', 'update_price', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER update_images'),
            ...$this->addColumn($pdo, 'xml_feeds', 'update_stock', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER update_price'),
            ...$this->addColumn($pdo, 'xml_feeds', 'auto_publish', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER update_stock'),
            ...$this->addColumn($pdo, 'xml_feeds', 'deactivate_missing', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER auto_publish'),
            ...$this->addColumn($pdo, 'xml_feeds', 'last_success_sync_at', 'DATETIME NULL AFTER last_import_at'),
            ...$this->addColumn($pdo, 'xml_feeds', 'last_file_path', 'VARCHAR(500) NULL AFTER last_success_sync_at'),
            ...$this->addForeignKey(
                $pdo,
                'xml_feeds',
                'fk_xml_feeds_default_category',
                'ADD CONSTRAINT fk_xml_feeds_default_category FOREIGN KEY (default_category_id)
                 REFERENCES categories (id) ON DELETE SET NULL'
            ),
        ];

        /* --- xml_import_logs --- */
        $sql = [
            ...$sql,
            ...$this->addColumn($pdo, 'xml_import_logs', 'deactivated_rows', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER skipped_rows'),
            ...$this->addColumn($pdo, 'xml_import_logs', 'warnings', 'TEXT NULL AFTER error_message'),
            ...$this->addColumn($pdo, 'xml_import_logs', 'file_path', 'VARCHAR(500) NULL AFTER file_name'),
        ];

        /* --- product_supplier_data --- */
        $sql = [
            ...$sql,
            ...$this->addColumn($pdo, 'product_supplier_data', 'xml_feed_id', 'BIGINT UNSIGNED NULL AFTER supplier_id'),
            ...$this->addColumn($pdo, 'product_supplier_data', 'last_seen_at', 'DATETIME NULL AFTER stock_updated_at'),
            ...$this->addColumn($pdo, 'product_supplier_data', 'protect_name', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER supplier_status'),
            ...$this->addColumn($pdo, 'product_supplier_data', 'protect_description', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER protect_name'),
            ...$this->addColumn($pdo, 'product_supplier_data', 'protect_price', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER protect_description'),
            ...$this->addColumn($pdo, 'product_supplier_data', 'protect_stock', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER protect_price'),
            ...$this->addColumn($pdo, 'product_supplier_data', 'protect_images', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER protect_stock'),
            ...$this->addIndex($pdo, 'product_supplier_data', 'idx_psd_feed', 'ADD KEY idx_psd_feed (xml_feed_id)'),
            ...$this->addIndex($pdo, 'product_supplier_data', 'idx_psd_last_seen', 'ADD KEY idx_psd_last_seen (xml_feed_id, last_seen_at)'),
            ...$this->addForeignKey(
                $pdo,
                'product_supplier_data',
                'fk_psd_feed',
                'ADD CONSTRAINT fk_psd_feed FOREIGN KEY (xml_feed_id) REFERENCES xml_feeds (id) ON DELETE SET NULL'
            ),
        ];

        /* --- product_price_history (yeni tablo) --- */
        $sql[] = 'CREATE TABLE IF NOT EXISTS product_price_history (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                supplier_id BIGINT UNSIGNED NULL,
                xml_feed_id BIGINT UNSIGNED NULL,
                import_log_id BIGINT UNSIGNED NULL,
                old_cost DECIMAL(15,4) NULL,
                new_cost DECIMAL(15,4) NULL,
                old_sale_price DECIMAL(15,4) NULL,
                new_sale_price DECIMAL(15,4) NULL,
                old_stock INT NULL,
                new_stock INT NULL,
                currency CHAR(3) NOT NULL DEFAULT "TRY",
                profit_margin DECIMAL(6,2) NULL,
                is_low_margin TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_pph_product (product_id),
                KEY idx_pph_created (created_at),
                KEY idx_pph_low_margin (is_low_margin),
                CONSTRAINT fk_pph_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
                CONSTRAINT fk_pph_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL,
                CONSTRAINT fk_pph_feed FOREIGN KEY (xml_feed_id) REFERENCES xml_feeds (id) ON DELETE SET NULL,
                CONSTRAINT fk_pph_import_log FOREIGN KEY (import_log_id) REFERENCES xml_import_logs (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        return $sql;
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS product_price_history');
        // Kolon düşüşleri isteğe bağlıdır; ileriye uyumluluk için bırakılır.
    }

    /* ----- koşullu DDL üreticileri ----- */

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function constraintExists(PDO $pdo, string $table, string $constraint): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
                   AND CONSTRAINT_TYPE IN ('FOREIGN KEY','UNIQUE')"
            );
            $stmt->execute([$table, $constraint]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
            );
            $stmt->execute([$table, $index]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return list<string> */
    private function addColumn(PDO $pdo, string $table, string $column, string $definition): array
    {
        return $this->columnExists($pdo, $table, $column)
            ? []
            : ['ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition];
    }

    /** @return list<string> */
    private function addIndex(PDO $pdo, string $table, string $index, string $definition): array
    {
        return $this->indexExists($pdo, $table, $index) ? [] : ['ALTER TABLE ' . $table . ' ' . $definition];
    }

    /** @return list<string> */
    private function addForeignKey(PDO $pdo, string $table, string $constraint, string $definition): array
    {
        return $this->constraintExists($pdo, $table, $constraint) ? [] : ['ALTER TABLE ' . $table . ' ' . $definition];
    }
}
