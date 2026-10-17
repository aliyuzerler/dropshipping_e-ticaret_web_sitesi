<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Core\Database;
use App\Database\MigrationInterface;
use PDO;
use Throwable;

/**
 * AŞAMA 3 — Mağaza ayarları (key/value) + ürün SEO alanları:
 *  - settings : mağaza bilgisi, kargo, eski stok kuralı, hukuki metinler
 *               (işletme bilgilerine göre düzenlenebilir)
 *  - products : meta_title / meta_description (boşsa sayfa başlığından türetilir)
 *
 * Tam yol: database/migrations/027_create_settings.php
 */
final class CreateSettings implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '027_create_settings';
    }

    public function up(): array
    {
        $pdo = Database::pdo();

        $sql[] = 'CREATE TABLE IF NOT EXISTS settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                setting_key VARCHAR(80) NOT NULL,
                setting_value TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_settings_key (setting_key)
            )' . self::T;

        $sql = [
            ...$sql,
            ...$this->addColumn($pdo, 'products', 'meta_title', 'VARCHAR(255) NULL AFTER slug'),
            ...$this->addColumn($pdo, 'products', 'meta_description', 'VARCHAR(500) NULL AFTER meta_title'),
        ];

        return $sql;
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS settings');
    }

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

    /** @return list<string> */
    private function addColumn(PDO $pdo, string $table, string $column, string $definition): array
    {
        return $this->columnExists($pdo, $table, $column)
            ? []
            : ['ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition];
    }
}
