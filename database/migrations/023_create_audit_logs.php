<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Denetim izleri (kim, ne, ne zaman, hangi kayıt).
 * Tam yol: database/migrations/023_create_audit_logs.php
 */
final class CreateAuditLogsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '023_create_audit_logs';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_user_id BIGINT UNSIGNED NULL,
                actor_type VARCHAR(20) NOT NULL DEFAULT "admin",
                action VARCHAR(100) NOT NULL,
                entity_type VARCHAR(60) NULL,
                entity_id BIGINT UNSIGNED NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                context JSON NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_audit_user_created (admin_user_id, created_at),
                KEY idx_audit_action (action),
                KEY idx_audit_entity (entity_type, entity_id),
                CONSTRAINT fk_audit_admin_user FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE SET NULL
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS audit_logs');
    }
}
