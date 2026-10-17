<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * customers tablosu erişimi (mağaza üyeleri).
 *
 * Tam yol: app/Repositories/CustomerRepository.php
 */
final class CustomerRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'customers';
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE email = ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email), 'UTF-8')]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    /**
     * Yeni müşteri kaydı; dönen satırın tamamı.
     * @return array<string,mixed>
     */
    public function create(array $data): array
    {
        $data['email'] = mb_strtolower(trim((string) $data['email'], 'UTF-8'));
        $data['status'] = $data['status'] ?? 'active';
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $id = $this->insert($data);
        /** @var array<string,mixed> $row */
        $row = $this->find($id) ?? [];
        return $row;
    }

    public function updatePasswordHash(int $id, string $hash): bool
    {
        $stmt = $this->pdo->prepare('UPDATE customers SET password_hash = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$hash, $id]);
    }

    public function registerFailure(int $id, int $failed, ?string $lockedUntil): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers SET failed_attempts = ?, locked_until = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$failed, $lockedUntil, $id]);
    }

    public function markLoginSuccess(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /** @return list<array<string,mixed>> (yönetim/test amaçlı) */
    public function allOrdered(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT id, first_name, last_name, email, phone, status, created_at FROM customers ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }
}
