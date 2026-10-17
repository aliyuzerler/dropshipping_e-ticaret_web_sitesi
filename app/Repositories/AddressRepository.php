<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * addresses tablosu erişimi — tüm sorgular customer_id ile sınırlıdır (IDOR koruması).
 *
 * Tam yol: app/Repositories/AddressRepository.php
 */
final class AddressRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'addresses';
    }

    /** @return list<array<string,mixed>> */
    public function allForCustomer(int $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, id ASC'
        );
        $stmt->execute([$customerId]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /** @return array<string,mixed>|null — kayıt müşteriye ait değilse NULL */
    public function findForCustomer(int $addressId, int $customerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM addresses WHERE id = ? AND customer_id = ? LIMIT 1');
        $stmt->execute([$addressId, $customerId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function defaultForCustomer(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM addresses WHERE customer_id = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $data */
    public function createForCustomer(int $customerId, array $data): int
    {
        $data['customer_id'] = $customerId;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        if (!empty($data['is_default'])) {
            $this->clearDefault($customerId);
        }
        return $this->insert($data);
    }

    /** @param array<string,mixed> $data */
    public function updateForCustomer(int $addressId, int $customerId, array $data): bool
    {
        if (!empty($data['is_default'])) {
            $this->clearDefault($customerId);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = ?';
        }
        $stmt = $this->pdo->prepare(
            'UPDATE addresses SET ' . implode(', ', $sets) . ' WHERE id = ? AND customer_id = ?'
        );
        return $stmt->execute([...array_values($data), $addressId, $customerId]);
    }

    public function deleteForCustomer(int $addressId, int $customerId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM addresses WHERE id = ? AND customer_id = ?');
        return $stmt->execute([$addressId, $customerId]);
    }

    public function setDefault(int $addressId, int $customerId): bool
    {
        $this->clearDefault($customerId);
        $stmt = $this->pdo->prepare(
            'UPDATE addresses SET is_default = 1, updated_at = NOW() WHERE id = ? AND customer_id = ?'
        );
        return $stmt->execute([$addressId, $customerId]);
    }

    private function clearDefault(int $customerId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE addresses SET is_default = 0 WHERE customer_id = ? AND is_default = 1'
        );
        $stmt->execute([$customerId]);
    }
}
