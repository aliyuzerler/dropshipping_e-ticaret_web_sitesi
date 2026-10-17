<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * refunds tablosu erişimi (AŞAMA 4 — iptal/iade).
 *
 * Tam yol: app/Repositories/RefundRepository.php
 */
final class RefundRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'refunds';
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM refunds WHERE order_id = ? ORDER BY id DESC');
        $stmt->execute([$orderId]);
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    /** Bekleyen iade talepleri (admin onay kuyruğu). @return list<array<string,mixed>> */
    public function pending(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, o.order_number, o.grand_total FROM refunds r
             INNER JOIN orders o ON o.id = r.order_id
             WHERE r.status = "pending" ORDER BY r.id ASC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    /** Sipariş için tamamlanan iade toplamı. */
    public function refundedTotal(int $orderId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE order_id = ? AND status = "completed"'
        );
        $stmt->execute([$orderId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** İşlem kilidiyle satır (transaction içinde). */
    public function lockById(int $refundId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM refunds WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$refundId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Admin liste ekranı: filtreli + sayfalı iade kayıtları. @return list<array<string,mixed>> */
    public function adminList(?string $status, int $limit, int $offset): array
    {
        if ($status !== null && $status !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT r.*, o.order_number FROM refunds r INNER JOIN orders o ON o.id = r.order_id
                 WHERE r.status = ? ORDER BY r.id DESC LIMIT ? OFFSET ?'
            );
            $stmt->bindValue(1, $status);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT r.*, o.order_number FROM refunds r INNER JOIN orders o ON o.id = r.order_id
                 ORDER BY r.id DESC LIMIT ? OFFSET ?'
            );
        }
        $stmt->bindValue($status !== null && $status !== '' ? 2 : 1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue($status !== null && $status !== '' ? 3 : 2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }
}
