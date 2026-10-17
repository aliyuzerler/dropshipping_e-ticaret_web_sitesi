<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * customer_password_resets tablosu erişimi.
 * Token'ın kendisi saklanmaz — yalnız SHA-256 hash'i (DB sızıntısına dayanıklı).
 *
 * Tam yol: app/Repositories/PasswordResetRepository.php
 */
final class PasswordResetRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'customer_password_resets';
    }

    public function create(int $customerId, string $tokenHash, string $expiresAt): int
    {
        // Eski tokenları geçersiz kıl (müşteri başına tek aktif istek)
        $this->deleteForCustomer($customerId);
        return $this->insert([
            'customer_id' => $customerId,
            'token_hash'  => $tokenHash,
            'expires_at'  => $expiresAt,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /** Kullanılmamış ve süresi geçmemiş token. @return array<string,mixed>|null */
    public function findValid(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM customer_password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE customer_password_resets SET used_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteForCustomer(int $customerId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM customer_password_resets WHERE customer_id = ?');
        $stmt->execute([$customerId]);
    }
}
