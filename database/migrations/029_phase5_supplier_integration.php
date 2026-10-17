<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;
use PDO;

/**
 * AŞAMA 5 — Tedarikçi siparişleri & entegrasyon altyapısı:
 *  - suppliers:  entegrasyon tipi (manual|api), API ucu + şifreli kimlik bilgileri
 *  - supplier_orders: benzersiz DAHİLİ referans (idempotency anahtarı), hata mesajı,
 *    gönderim denemesi sayacı, teslimat snapshot'ı, gönderen (api|manual), durum zamanı
 *  - shipments: (kargo firması + takip no) benzersizliği, not alanı
 *
 * Idempotentlik MariaDB IF NOT EXISTS DDL desteğiyle sağlanır (Aşama 4 standardı).
 *
 * Tam yol: database/migrations/029_phase5_supplier_integration.php
 */
final class Phase5SupplierIntegration implements MigrationInterface
{
    public function name(): string
    {
        return '029_phase5_supplier_integration';
    }

    public function up(): array
    {
        return [
            // --- suppliers: API entegrasyon alanları (kimlik bilgileri ŞİFRELİ saklanır) ---
            "ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS integration_type VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER is_active",
            'ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS api_endpoint VARCHAR(255) NULL AFTER integration_type',
            "ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS api_auth VARCHAR(20) NULL AFTER api_endpoint",
            'ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS api_credentials_enc TEXT NULL AFTER api_auth',

            // --- supplier_orders: idempotent gönderim + hata/iletim alanları ---
            'ALTER TABLE supplier_orders ADD COLUMN IF NOT EXISTS internal_reference VARCHAR(64) NULL AFTER order_id',
            'ALTER TABLE supplier_orders ADD UNIQUE INDEX IF NOT EXISTS uniq_supplier_orders_internal_ref (internal_reference)',
            'ALTER TABLE supplier_orders ADD COLUMN IF NOT EXISTS sent_by VARCHAR(20) NULL AFTER external_reference',
            'ALTER TABLE supplier_orders ADD COLUMN IF NOT EXISTS error_message TEXT NULL AFTER external_reference',
            'ALTER TABLE supplier_orders ADD COLUMN IF NOT EXISTS attempts INT NOT NULL DEFAULT 0 AFTER error_message',
            'ALTER TABLE supplier_orders ADD COLUMN IF NOT EXISTS delivery_snapshot TEXT NULL AFTER attempts',
            'ALTER TABLE supplier_orders ADD COLUMN IF NOT EXISTS status_updated_at DATETIME NULL AFTER sent_at',

            // mevcut kayıtlar için deterministik dahili referans (SORD-{sipariş no}-S{tedarikçi id})
            "UPDATE supplier_orders SET internal_reference = CONCAT('SORD-', (SELECT order_number FROM orders WHERE orders.id = supplier_orders.order_id), '-S', supplier_id)
             WHERE internal_reference IS NULL",

            // --- shipments: takip numarası benzersizliği + not alanı ---
            'ALTER TABLE shipments ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER tracking_url',
            'ALTER TABLE shipments ADD UNIQUE INDEX IF NOT EXISTS uniq_shipments_carrier_tracking (carrier, tracking_number)',
        ];
    }

    public function down(PDO $pdo): void
    {
        // Yapısal alanlar projede kalıcıdır (down yalnız tam temizlik içindir).
        $pdo->exec('ALTER TABLE suppliers DROP COLUMN IF EXISTS api_credentials_enc');
        $pdo->exec('ALTER TABLE suppliers DROP COLUMN IF EXISTS api_auth');
        $pdo->exec('ALTER TABLE suppliers DROP COLUMN IF EXISTS api_endpoint');
        $pdo->exec('ALTER TABLE suppliers DROP COLUMN IF EXISTS integration_type');
    }
}
