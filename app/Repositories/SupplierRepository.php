<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * suppliers tablosu erişimi.
 *
 * Tam yol: app/Repositories/SupplierRepository.php
 */
final class SupplierRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'suppliers';
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suppliers WHERE code = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function activeSuppliers(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM suppliers WHERE is_active = 1 ORDER BY name');
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        return $rows;
    }

    /* ---------- AŞAMA 5 — entegrasyon alanları ---------- */

    /** @return list<array<string,mixed>> */
    public function allSuppliers(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM suppliers ORDER BY name');
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        return $rows;
    }

    /**
     * API entegrasyonu yapılandırılmış mı? (uç + kimlik bilgisi şart)
     */
    public function hasApiConfig(array $supplier): bool
    {
        return (string) ($supplier['integration_type'] ?? 'manual') === 'api'
            && trim((string) ($supplier['api_endpoint'] ?? '')) !== ''
            && trim((string) ($supplier['api_credentials_enc'] ?? '')) !== '';
    }
}
