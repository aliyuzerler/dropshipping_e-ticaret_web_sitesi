<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Webhook olayları — UNIQUE(provider, external_id) ile idempotent işleme.
 * Tam yol: database/migrations/022_create_webhook_events.php
 */
final class CreateWebhookEventsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '022_create_webhook_events';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE webhook_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider VARCHAR(30) NOT NULL,
                event_type VARCHAR(60) NOT NULL,
                external_id VARCHAR(100) NOT NULL,
                payload JSON NULL,
                signature_valid TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT "received",
                processed_at DATETIME NULL,
                error_message VARCHAR(500) NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_webhook_provider_external (provider, external_id),
                KEY idx_webhook_status_created (status, created_at),
                KEY idx_webhook_provider_type (provider, event_type)
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS webhook_events');
    }
}
