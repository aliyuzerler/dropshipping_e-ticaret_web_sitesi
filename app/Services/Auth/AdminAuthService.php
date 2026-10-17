<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Session;
use App\Repositories\AdminUserRepository;

/**
 * Admin oturum işlemleri: giriş (kilitlemeli), çıkış, kontrol.
 *
 * Tam yol: app/Services/Auth/AdminAuthService.php
 */
final class AdminAuthService
{
    private AdminUserRepository $users;

    public function __construct(?AdminUserRepository $users = null)
    {
        $this->users = $users ?? new AdminUserRepository();
    }

    public function check(): bool
    {
        return is_int(Session::get('admin_id')) && Session::get('admin_id') > 0;
    }

    public function currentAdminId(): ?int
    {
        $id = Session::get('admin_id');
        return is_int($id) && $id > 0 ? $id : null;
    }

    /**
     * @return bool giriş başarılı mı
     */
    public function attempt(string $email, string $password, string $ip, string $userAgent): bool
    {
        $maxAttempts = (int) config('security.admin.max_failed_attempts', 5);
        $lockMinutes = (int) config('security.admin.lockout_minutes', 15);

        $user = $this->users->findByEmail($email);

        if ($user === null) {
            $this->logFailure(null, $email, $ip, $userAgent, 'user_not_found');
            return false;
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            Logger::security('admin.login_locked', ['email' => $email, 'ip' => $ip]);
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $failed = ((int) $user['failed_attempts']) + 1;
            $lockedUntil = $failed >= $maxAttempts ? date('Y-m-d H:i:s', time() + $lockMinutes * 60) : null;
            $this->users->registerFailure((int) $user['id'], $failed, $lockedUntil);
            $this->logFailure((int) $user['id'], $email, $ip, $userAgent, 'bad_password');
            return false;
        }

        $algo = config('security.password.algo') ?? PASSWORD_BCRYPT;
        if (password_needs_rehash((string) $user['password_hash'], $algo, ['cost' => (int) config('security.password.cost', 12)])) {
            $this->users->updatePasswordHash((int) $user['id'], $password);
        }

        // Başarı: oturum sabitleme koruması + sayaç sıfırlama
        Session::regenerate();
        Session::set('admin_id', (int) $user['id']);
        Session::set('admin_email', (string) $user['email']);
        Session::set('admin_role', (string) $user['role']);
        $this->users->markLoginSuccess((int) $user['id']);

        \App\Services\Audit\AuditLogger::log(
            action: 'admin.login',
            adminUserId: (int) $user['id'],
            ip: $ip,
            userAgent: $userAgent
        );

        return true;
    }

    public function logout(string $ip, string $userAgent): void
    {
        $adminId = $this->currentAdminId();
        if ($adminId !== null) {
            \App\Services\Audit\AuditLogger::log('admin.logout', $adminId, $ip, $userAgent);
        }
        Session::destroy();
    }

    private function logFailure(?int $userId, string $email, string $ip, string $userAgent, string $reason): void
    {
        Logger::security('admin.login_failed', ['email' => $email, 'ip' => $ip, 'reason' => $reason]);
        \App\Services\Audit\AuditLogger::log(
            action: 'admin.login_failed',
            adminUserId: $userId,
            ip: $ip,
            userAgent: $userAgent,
            context: ['email' => $email, 'reason' => $reason]
        );
    }

    /** Seed / ilk kurulum için şifre hash üretici. */
    public static function hashPassword(string $password): string
    {
        $algo = config('security.password.algo') ?? PASSWORD_BCRYPT; // string sabit: "2y"
        return password_hash($password, $algo, ['cost' => (int) config('security.password.cost', 12)]);
    }
}
