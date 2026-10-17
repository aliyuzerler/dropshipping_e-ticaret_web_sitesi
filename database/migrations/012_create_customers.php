<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Müşteriler (misafir checkout için password_hash NULL olabilir).
 * Tam yol: database/migrations/012_create_customers.php
 */
final class CreateCustomersTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '012_create_customers';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE customers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                first_name VARCHAR(80) NULL,
                last_name VARCHAR(80) NULL,
                email VARCHAR(180) NOT NULL,
                phone VARCHAR(40) NULL,
                password_hash VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT "active",
                email_verified_at DATETIME NULL,
                last_login_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_customers_email (email),
                KEY idx_customers_status (status)
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS customers');
    }
}
