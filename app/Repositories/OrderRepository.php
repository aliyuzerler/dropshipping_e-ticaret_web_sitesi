<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * orders + order_items erişimi. Snapshot kolonları fatura güvenliği için korunur.
 *
 * Tam yol: app/Repositories/OrderRepository.php
 */
final class OrderRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'orders';
    }

    /** Eşsiz sipariş numarası üretir (çakışma ihtimaline karşı 3 deneme). */
    public function uniqueOrderNumber(): string
    {
        for ($i = 0; $i < 3; $i++) {
            $number = generate_order_number();
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_number = ?');
            $stmt->execute([$number]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $number;
            }
        }
        return generate_order_number() . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    /**
     * Sipariş + kalemleri TEK transaction içinde yazar.
     * @param array<string,mixed> $order
     * @param list<array<string,mixed>> $items
     * @return int order id
     */
    public function placeWithItems(array $order, array $items): int
    {
        $orderId = $this->insert($order);
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_items
             (order_id, product_id, variant_id, supplier_id, product_name_snapshot, sku_snapshot,
              quantity, unit_price, tax_rate, tax_amount, line_total, currency, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
        );
        foreach ($items as $item) {
            $stmt->execute([
                $orderId,
                $item['product_id'],
                $item['variant_id'],
                $item['supplier_id'] ?? null,
                $item['product_name_snapshot'],
                $item['sku_snapshot'],
                $item['quantity'],
                $item['unit_price'],
                $item['tax_rate'],
                $item['tax_amount'],
                $item['line_total'],
                $item['currency'] ?? 'TRY',
            ]);
        }
        return $orderId;
    }

    /** @return list<array<string,mixed>> */
    public function forCustomer(int $customerId, int $limit = 10, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
             FROM orders o WHERE o.customer_id = ? ORDER BY o.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $customerId, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    public function countForCustomer(int $customerId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM orders WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        return (int) $stmt->fetchColumn();
    }

    /** Siparişi yalnızca SAHİBİ müşteriyle döndürür (IDOR koruması). @return array<string,mixed>|null */
    public function findByNumberForCustomer(string $orderNumber, int $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM orders WHERE order_number = ? AND customer_id = ? LIMIT 1'
        );
        $stmt->execute([$orderNumber, $customerId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function itemsForOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $stmt->execute([$orderId]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /* ================= AŞAMA 4 — admin / ödeme akışı ================= */

    /** Sipariş numarasına göre SAHİP kontrolü olmadan arar (yalnız admin/ödeme servisi). */
    public function findAnyByNumber(string $orderNumber): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([$orderNumber]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** AŞAMA 5: id'ye göre arar. */
    public function find(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** AŞAMA 5: id'ye göre satır kilidiyle arar (gönderim/ödemede yarış koruması). */
    public function lockByIdForUpdate(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Admin listesi: durum + arama filtreli, sayfalı. @return list<array<string,mixed>> */
    public function adminList(?string $status, string $q, int $limit, int $offset): array
    {
        [$where, $params] = $this->adminFilter($status, $q);
        $stmt = $this->pdo->prepare(
            'SELECT o.*, c.email AS customer_email, c.first_name, c.last_name,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
             ' . $where . ' ORDER BY o.id DESC LIMIT ? OFFSET ?'
        );
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p);
        }
        $stmt->bindValue(count($params) + 1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    public function countAdmin(?string $status, string $q): int
    {
        [$where, $params] = $this->adminFilter($status, $q);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM orders o ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Durum bazlı sipariş sayaçları (admin özet kartları). @return array<string,int> */
    public function statusCounts(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status')->fetchAll();
        $out  = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array{0:string, 1:list<mixed>} */
    private function adminFilter(?string $status, string $q): array
    {
        $conds  = [];
        $params = [];
        if ($status !== null && $status !== '') {
            $conds[]  = 'o.status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $conds[]  = '(o.order_number LIKE ? OR c.email LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        return [$conds === [] ? '' : 'WHERE ' . implode(' AND ', $conds), $params];
    }
}
