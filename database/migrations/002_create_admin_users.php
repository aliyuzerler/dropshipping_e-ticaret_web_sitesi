<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Yönetici kullanıcıları (brute-force kilidi alanları dahil).
 * Tam yol: database/migrations/002_create_admin_users.php
 */
final class CreateAdminUsersTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '002_create_admin_users';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE admin_users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(180) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT "admin",
                status VARCHAR(20) NOT NULL DEFAULT "active",
                last_login_at DATETIME NULL,
                failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_admin_users_email (email),
                KEY idx_admin_users_role (role)
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_users');
    }
}
