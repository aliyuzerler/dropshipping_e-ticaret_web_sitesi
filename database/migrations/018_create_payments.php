<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * Ödemeler. Finansal kayıt: order silinemez (RESTRICT). Kart numarası saklanmaz.
 * Tam yol: database/migrations/018_create_payments.php
 */
final class CreatePaymentsTable implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '018_create_payments';
    }

    public function up(): array
    {
        return [
            'CREATE TABLE payments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                provider VARCHAR(30) NOT NULL DEFAULT "iyzico",
                provider_payment_id VARCHAR(100) NULL,
                conversation_id VARCHAR(100) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "initiated",
                amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT "TRY",
                installment SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                card_last_four CHAR(4) NULL,
                card_association VARCHAR(30) NULL,
                error_code VARCHAR(50) NULL,
                error_message VARCHAR(255) NULL,
                raw_response JSON NULL,
                initiated_at DATETIME NULL,
                completed_at DATETIME NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_payments_provider_id (provider, provider_payment_id),
                KEY idx_payments_order (order_id, status),
                CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
            )' . self::T,
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS payments');
    }
}
