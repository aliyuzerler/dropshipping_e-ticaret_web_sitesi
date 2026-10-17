<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Sepetler (misafir sepeti desteklenir: session_token).
 * Tam yol: database/migrations/014_create_carts.php
 */
final class CreateCartsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '014_create_carts';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE carts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NULL,
                session_token CHAR(64) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "active",
                currency CHAR(3) NOT NULL DEFAULT "TRY",
                subtotal DECIMAL(15,2) NULL,
                expires_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_carts_session_token (session_token),
                KEY idx_carts_customer (customer_id),
                KEY idx_carts_status (status),
                CONSTRAINT fk_carts_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS carts');
    }
}
