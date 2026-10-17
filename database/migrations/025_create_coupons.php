<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Core\Database;
use App\Database\MigrationInterface;
use PDO;
use Throwable;

/**
 * AŞAMA 3 — Kupon altyapısı + sepet/sipariş kupon referansı.
 *  - coupons : kod, tip (percent|fixed), değer, alt sınır, üst indirim sınırı,
 *              kullanım limiti, tarih aralığı, aktiflik
 *  - orders  : coupon_code (hangi kuponla verildiği — izlenebilirlik)
 *  - carts   : coupon_code (sepette uygulanmış kupon)
 *
 * Tam yol: database/migrations/025_create_coupons.php
 */
final class CreateCoupons implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '025_create_coupons';
    }

    public function up(): array
    {
        $pdo = Database::pdo();

        $sql[] = 'CREATE TABLE IF NOT EXISTS coupons (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(40) NOT NULL,
                description VARCHAR(255) NULL,
                type VARCHAR(20) NOT NULL DEFAULT "percent",
                value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                min_order_total DECIMAL(15,2) NULL,
                max_discount DECIMAL(15,2) NULL,
                usage_limit INT UNSIGNED NULL,
                used_count INT UNSIGNED NOT NULL DEFAULT 0,
                starts_at DATETIME NULL,
                expires_at DATETIME NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_coupons_code (code),
                KEY idx_coupons_active (is_active, expires_at)
            )' . self::T;

        $sql = [
            ...$sql,
            ...$this->addColumn($pdo, 'orders', 'coupon_code', 'VARCHAR(40) NULL AFTER discount_total'),
            ...$this->addColumn($pdo, 'carts', 'coupon_code', 'VARCHAR(40) NULL AFTER status'),
        ];

        return $sql;
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS coupons');
        // Kolon düşüşleri isteğe bağlıdır; ileriye uyumluluk için bırakılır.
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
