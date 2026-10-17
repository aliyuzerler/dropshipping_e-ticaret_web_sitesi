<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;
use PDO;

/**
 * AŞAMA 5 — E-posta bildirim altyapısı.
 *
 * dedup_key UNIQUE → aynı olay için mükerrer bildirim imkânsızdır (callback +
 * webhook aynı olayı tetiklese bile tek satır oluşur). Gönderim başarısızlıkları
 * attempts/last_error alanlarında izlenir; kuyruk cron ile tekrar denenir.
 *
 * Tam yol: database/migrations/030_phase5_notifications.php
 */
final class Phase5Notifications implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '030_phase5_notifications';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event VARCHAR(60) NOT NULL,
                recipient_email VARCHAR(180) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                ref_type VARCHAR(30) NULL,
                ref_id BIGINT UNSIGNED NULL,
                dedup_key VARCHAR(160) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "pending",
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_notifications_dedup (dedup_key),
                KEY idx_notifications_status (status, attempts, created_at),
                KEY idx_notifications_event (event),
                KEY idx_notifications_ref (ref_type, ref_id)
            )' . self::T,
        ];
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS notifications');
    }
}
