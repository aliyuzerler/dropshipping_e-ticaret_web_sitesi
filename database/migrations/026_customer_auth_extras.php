<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Core\Database;
use App\Database\MigrationInterface;
use PDO;
use Throwable;

/**
 * AŞAMA 3 — Müşteri üyelik altyapısı:
 *  - customer_password_resets : tek kullanımlık, hash'li sıfırlama tokenları
 *  - customers                : başarısız giriş sayacı + hesap kilidi (brute-force savunması)
 *
 * Tam yol: database/migrations/026_customer_auth_extras.php
 */
final class CustomerAuthExtras implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '026_customer_auth_extras';
    }

    public function up(): array
    {
        $pdo = Database::pdo();

        $sql[] = 'CREATE TABLE IF NOT EXISTS customer_password_resets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_cpr_token (token_hash),
                KEY idx_cpr_customer (customer_id),
                CONSTRAINT fk_cpr_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE
            )' . self::T;

        $sql = [
            ...$sql,
            ...$this->addColumn($pdo, 'customers', 'failed_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER status'),
            ...$this->addColumn($pdo, 'customers', 'locked_until', 'DATETIME NULL AFTER failed_attempts'),
        ];

        return $sql;
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS customer_password_resets');
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
