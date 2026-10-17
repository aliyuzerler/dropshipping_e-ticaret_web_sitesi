<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;
use PDO;

/**
 * AŞAMA 4 — Sipariş/iyzico ödeme altyapısı:
 *  - payments: token (UNIQUE — Checkout Form token'ı), basket_id
 *  - orders:   varsayılan durum 'pending_payment' (AŞAMA 4 durum makinesi)
 *  - refunds:  iptal/iade kayıtları (tam + kısmi, işlem referansları)
 *
 * Idempotentlik, MariaDB'nin IF NOT EXISTS DDL desteğiyle SQL düzeyinde sağlanır
 * (bağlam-bağımsız: MigrationRunner hangi bağlantıya verilirse orada çalışır).
 * NOT: `ADD COLUMN IF NOT EXISTS` / `ADD UNIQUE INDEX IF NOT EXISTS` MariaDB
 * sözdizimidir; saf MySQL 8'de koşullar elle işletilmelidir (proje standardı
 * MariaDB portable olduğu için bu yönem seçildi — diğer migration'lar da aynı
 * ortamda doğrulanmıştır).
 *
 * Tam yol: database/migrations/028_phase4_payments.php
 */
final class Phase4Payments implements MigrationInterface
{
    private const T = ' ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

    public function name(): string
    {
        return '028_phase4_payments';
    }

    public function up(): array
    {
        return [
            // payments.token — iyzico Checkout Form token'ı (sonuç sorgulama anahtarı, UNIQUE)
            'ALTER TABLE payments ADD COLUMN IF NOT EXISTS token VARCHAR(128) NULL AFTER conversation_id',
            // payments.basket_id — siparişle ilişkili sepet kimliği
            'ALTER TABLE payments ADD COLUMN IF NOT EXISTS basket_id VARCHAR(100) NULL AFTER conversation_id',
            // UNIQUE token index'i → callback/webhook idempotency anahtarı
            'ALTER TABLE payments ADD UNIQUE INDEX IF NOT EXISTS uniq_payments_token (token)',
            // orders varsayılan durumu: ödeme akışı 'pending_payment' ile başlar
            "ALTER TABLE orders MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending_payment'",
            // refunds — iptal/iade kayıtları
            'CREATE TABLE IF NOT EXISTS refunds (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT UNSIGNED NOT NULL,
                payment_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT "refund",
                scope VARCHAR(20) NOT NULL DEFAULT "full",
                status VARCHAR(20) NOT NULL DEFAULT "pending",
                amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                currency CHAR(3) NOT NULL DEFAULT "TRY",
                payment_transaction_id VARCHAR(100) NULL,
                refund_reference VARCHAR(100) NULL,
                context JSON NULL,
                reason VARCHAR(255) NULL,
                note VARCHAR(500) NULL,
                error_message VARCHAR(500) NULL,
                restore_status VARCHAR(30) NULL,
                requested_by VARCHAR(20) NOT NULL DEFAULT "customer",
                requested_by_id BIGINT UNSIGNED NULL,
                handled_by BIGINT UNSIGNED NULL,
                created_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NULL DEFAULT NULL,
                completed_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_refunds_order (order_id, status),
                KEY idx_refunds_payment (payment_id),
                KEY idx_refunds_status (status, created_at),
                CONSTRAINT fk_refunds_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE RESTRICT
            )' . self::T,
        ];
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS refunds');
        $pdo->exec('ALTER TABLE payments DROP INDEX IF EXISTS uniq_payments_token');
        $pdo->exec('ALTER TABLE payments DROP COLUMN IF EXISTS token');
        $pdo->exec('ALTER TABLE payments DROP COLUMN IF EXISTS basket_id');
    }
}
