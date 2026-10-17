<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * admin_users tablosu erişimi.
 *
 * Tam yol: app/Repositories/AdminUserRepository.php
 */
final class AdminUserRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'admin_users';
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function registerFailure(int $id, int $failedCount, ?string $lockedUntil): void
    {
        $stmt = $this->pdo->prepare('UPDATE admin_users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
        $stmt->execute([$failedCount, $lockedUntil, $id]);
    }

    public function updatePasswordHash(int $id, string $plainPassword): void
    {
        $hash = \App\Services\Auth\AdminAuthService::hashPassword($plainPassword);
        $stmt = $this->pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);
    }

    public function markLoginSuccess(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }
}
