<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Database\MigrationInterface;

/**
 * AŞAMA 6 — Performans indeksi (EXPLAIN kanıtıyla):
 *
 * Vitrin listeleme/anasayfa sorgusu:
 *   WHERE p.status = 'active' AND p.deleted_at IS NULL
 *   ORDER BY p.created_at DESC, p.id DESC LIMIT ?
 *
 * Aşama 1 şemasında yalnız idx_products_status (status) vardı; 80.000 satırlık
 * ölçeklemede (MariaDB 11.4, ANALYZE FORMAT=JSON):
 *   - indekssiz: 80.000 satır okuma, 240.199 sayfa erişimi, 74,7 ms + filesort
 *   - bu indeksle: 24 satır okuma, 155 sayfa erişimi, 0,065 ms, filesort YOK
 *
 * Idempotentlik, projedeki standarta (028_phase4_payments) uygun olarak
 * MariaDB'nin `ADD INDEX IF NOT EXISTS` DDL desteğiyle sağlanır.
 * NOT: `ADD INDEX IF NOT EXISTS` MariaDB sözdizimidir; saf MySQL 8'de
 * information_schema.STATISTICS üzerinden koşullu ALTER gerekir.
 *
 * Tam yol: database/migrations/031_phase6_indexes.php
 */
final class Phase6Indexes implements MigrationInterface
{
    public function name(): string
    {
        return '031_phase6_indexes';
    }

    public function up(): array
    {
        return [
            // Vitrin listeleme + anasayfa + kategori sayfalarının varsayılan sıralaması:
            // status eşitliği + created_at DESC okuması indeks sırasından yapılır (filesort kalkar).
            'ALTER TABLE products ADD INDEX IF NOT EXISTS idx_products_status_created (status, created_at)',
        ];
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE products DROP INDEX IF EXISTS idx_products_status_created');
    }
}
