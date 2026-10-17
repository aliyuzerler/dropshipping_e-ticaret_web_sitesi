<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * carts tablosu erişimi (misafir + üye sepetleri).
 *
 * Tam yol: app/Repositories/CartRepository.php
 */
final class CartRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'carts';
    }

    /** @return array<string,mixed>|null */
    public function findActiveBySession(string $sessionToken): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM carts WHERE session_token = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$sessionToken]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findActiveByCustomer(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM carts WHERE customer_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed> */
    public function create(?int $customerId, string $sessionToken): array
    {
        $id = $this->insert([
            'customer_id'   => $customerId,
            'session_token' => $sessionToken,
            'status'        => 'active',
            'currency'      => 'TRY',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
        /** @var array<string,mixed> $row */
        $row = $this->find($id) ?? [];
        return $row;
    }

    public function attachCustomer(int $cartId, int $customerId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE carts SET customer_id = ?, updated_at = NOW() WHERE id = ? AND status = 'active'"
        );
        return $stmt->execute([$customerId, $cartId]);
    }

    public function setStatus(int $cartId, string $status): bool
    {
        $stmt = $this->pdo->prepare('UPDATE carts SET status = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$status, $cartId]);
    }

    public function setCouponCode(int $cartId, ?string $code): bool
    {
        $stmt = $this->pdo->prepare('UPDATE carts SET coupon_code = ?, updated_at = NOW() WHERE id = ?');
        return $stmt->execute([$code, $cartId]);
    }
}
