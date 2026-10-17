<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Logger;
use App\Core\Session;
use App\Exceptions\ValidationException;
use App\Repositories\CustomerRepository;
use App\Repositories\PasswordResetRepository;
use App\Services\Store\CartService;

/**
 * Mağaza müşteri oturum işlemleri: kayıt, giriş (kilitlemeli), çıkış,
 * şifre sıfırlama (hash'li tek kullanımlık token).
 *
 * Tam yol: app/Services/Auth/CustomerAuthService.php
 */
final class CustomerAuthService
{
    private CustomerRepository $users;

    private PasswordResetRepository $resets;

    private CartService $cart;

    public function __construct(
        ?CustomerRepository $users = null,
        ?PasswordResetRepository $resets = null,
        ?CartService $cart = null
    ) {
        $this->users = $users ?? new CustomerRepository();
        $this->resets = $resets ?? new PasswordResetRepository();
        $this->cart = $cart ?? new CartService();
    }

    public function check(): bool
    {
        $id = Session::get('customer_id');
        return is_int($id) && $id > 0;
    }

    public function currentCustomerId(): ?int
    {
        $id = Session::get('customer_id');
        return is_int($id) && $id > 0 ? $id : null;
    }

    /** @return array<string,mixed>|null */
    public function currentCustomer(): ?array
    {
        $id = $this->currentCustomerId();
        return $id === null ? null : $this->users->find($id);
    }

    /**
     * Kayıt — doğrulama hatalarında ValidationException (422).
     * $input: first_name, last_name, email, phone, password, password_confirmation
     *
     * @return array<string,mixed> yeni müşteri satırı
     */
    public function register(array $input): array
    {
        $errors = [];

        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirmation'] ?? '');

        if ($firstName === '' || mb_strlen($firstName, 'UTF-8') > 80) {
            $errors['first_name'] = ['Ad zorunludur (en fazla 80 karakter).'];
        }
        if ($lastName === '' || mb_strlen($lastName, 'UTF-8') > 80) {
            $errors['last_name'] = ['Soyad zorunludur (en fazla 80 karakter).'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 180) {
            $errors['email'] = ['Geçerli bir e-posta adresi girin.'];
        } elseif ($this->users->emailExists($email)) {
            $errors['email'] = ['Bu e-posta adresi zaten kayıtlı.'];
        }
        if ($phone !== '' && mb_strlen($phone, 'UTF-8') > 40) {
            $errors['phone'] = ['Telefon en fazla 40 karakter olabilir.'];
        }
        if (strlen($password) < 8) {
            $errors['password'] = ['Şifre en az 8 karakter olmalıdır.'];
        } elseif ($password !== $confirmation) {
            $errors['password_confirmation'] = ['Şifreler eşleşmiyor.'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $customer = $this->users->create([
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'email'         => $email,
            'phone'         => $phone !== '' ? $phone : null,
            'password_hash' => self::hashPassword($password),
        ]);

        $this->startSession($customer);
        Logger::info('app', 'customer.registered', ['customer_id' => (int) $customer['id']]);

        return $customer;
    }

    /**
     * Giriş — 5 hatalı denemede 15 dk hesap kilidi (admin ile aynı politika).
     * Başarılı girişte misafir sepeti üye sepetiyle birleştirilir.
     */
    public function attempt(string $email, string $password, string $ip, string $userAgent): bool
    {
        $maxAttempts = 5;
        $lockMinutes = 15;

        $user = $this->users->findByEmail($email);

        if ($user === null || $user['password_hash'] === null) {
            $this->logFailure($user === null ? null : (int) $user['id'], $email, $ip, 'user_not_found');
            return false;
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            Logger::security('customer.login_locked', ['email' => $email, 'ip' => $ip]);
            return false;
        }

        if ($user['status'] !== 'active') {
            Logger::security('customer.login_blocked_status', ['email' => $email, 'ip' => $ip]);
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $failed = ((int) $user['failed_attempts']) + 1;
            $lockedUntil = $failed >= $maxAttempts ? date('Y-m-d H:i:s', time() + $lockMinutes * 60) : null;
            $this->users->registerFailure((int) $user['id'], $failed, $lockedUntil);
            $this->logFailure((int) $user['id'], $email, $ip, 'bad_password');
            return false;
        }

        $cost = (int) config('security.password.cost', 12);
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_BCRYPT, ['cost' => $cost])) {
            $this->users->updatePasswordHash((int) $user['id'], password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]));
        }

        $this->startSession($user);
        $this->users->markLoginSuccess((int) $user['id']);

        // Misafir sepetini üye sepetine birleştir
        try {
            $this->cart->mergeGuestCartIntoCustomer((int) $user['id']);
        } catch (\Throwable $e) {
            Logger::error('app', 'cart.merge_failed', ['message' => $e->getMessage()]);
        }

        return true;
    }

    public function logout(): void
    {
        Session::remove('customer_id');
        Session::remove('customer_name');
        Session::remove('customer_email');
    }

    /**
     * Şifre sıfırlama talebi. E-posta bilinmiyorsa NULL döner (kaydın
     * varlığı ifşa edilmez — yanıt her zaman aynı).
     */
    public function sendResetLink(string $email): ?string
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            Logger::security('customer.reset_unknown_email', ['email' => $email]);
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $this->resets->create(
            (int) $user['id'],
            hash('sha256', $token),
            date('Y-m-d H:i:s', time() + 3600) // 1 saat
        );

        Logger::info('app', 'customer.reset_requested', ['customer_id' => (int) $user['id']]);
        return $token;
    }

    /** Token ile şifre güncelleme — hatalarda ValidationException. */
    public function resetPassword(string $token, string $password, string $confirmation): void
    {
        $errors = [];
        if (strlen($password) < 8) {
            $errors['password'] = ['Şifre en az 8 karakter olmalıdır.'];
        } elseif ($password !== $confirmation) {
            $errors['password_confirmation'] = ['Şifreler eşleşmiyor.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $reset = $this->resets->findValid(hash('sha256', $token));
        if ($reset === null) {
            throw new ValidationException(['token' => ['Sıfırlama bağlantısı geçersiz veya süresi dolmuş.']]);
        }

        $this->users->updatePasswordHash((int) $reset['customer_id'], self::hashPassword($password));
        $this->resets->markUsed((int) $reset['id']);
        Logger::info('app', 'customer.reset_completed', ['customer_id' => (int) $reset['customer_id']]);
    }

    /* ----- iç yardımcılar ----- */

    private function startSession(array $customer): void
    {
        // Oturum sabitleme koruması
        Session::regenerate();
        Session::set('customer_id', (int) $customer['id']);
        Session::set('customer_email', (string) $customer['email']);
        Session::set('customer_name', trim((string) ($customer['first_name'] ?? '') . ' ' . (string) ($customer['last_name'] ?? '')));
    }

    private function logFailure(?int $customerId, string $email, string $ip, string $reason): void
    {
        Logger::security('customer.login_failed', ['email' => $email, 'ip' => $ip, 'reason' => $reason]);
    }

    public static function hashPassword(string $password): string
    {
        $cost = (int) config('security.password.cost', 12);
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}
