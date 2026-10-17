<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * coupons tablosu erişimi.
 *
 * Tam yol: app/Repositories/CouponRepository.php
 */
final class CouponRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'coupons';
    }

    /** Kod; büyük/küçük harf duyarsız (normalize: trim + upper). @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE UPPER(code) = UPPER(?) LIMIT 1');
        $stmt->execute([trim($code)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function incrementUsage(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE coupons SET used_count = used_count + 1, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Kupon formu için select listesi (admin/seed). @return list<array<string,mixed>> */
    public function allActive(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM coupons WHERE is_active = 1 ORDER BY code');
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        return $rows;
    }
}
