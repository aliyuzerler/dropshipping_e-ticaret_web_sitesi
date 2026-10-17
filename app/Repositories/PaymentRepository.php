<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * payments tablosu erişimi (AŞAMA 4). Token UNIQUE — callback/webhook
 * idempotency'si bu anahtar üzerinden kurulur.
 *
 * Tam yol: app/Repositories/PaymentRepository.php
 */
final class PaymentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'payments';
    }

    /** Token'a göre ödeme kaydı (callback/webhook işlemenin giriş noktası). */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Siparişin ödemeleri (yeni → eski). @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC');
        $stmt->execute([$orderId]);
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    /** Siparişin en güncel ödemesi. */
    public function latestForOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Siparişin başarılı ödemesi (iptal/iade akışları için). */
    public function successForOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM payments WHERE order_id = ? AND status = 'success' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** İşlem kilidiyle (FOR UPDATE) satırı çeker — transaction içinde kullanılır. */
    public function lockById(int $paymentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Siparişi kilitlemek için order satırını FOR UPDATE çeker. */
    public function lockOrder(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
