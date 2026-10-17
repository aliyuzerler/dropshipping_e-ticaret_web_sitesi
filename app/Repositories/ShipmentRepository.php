<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * shipments tablosu erişimi (AŞAMA 5 — kargo yönetimi).
 *
 * Tam yol: app/Repositories/ShipmentRepository.php
 */
final class ShipmentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'shipments';
    }

    public function forSupplierOrder(int $supplierOrderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM shipments WHERE supplier_order_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$supplierOrderId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sh.*, so.order_id, so.supplier_id, so.internal_reference, o.order_number,
                    s.name AS supplier_name
             FROM shipments sh
             LEFT JOIN supplier_orders so ON so.id = sh.supplier_order_id
             LEFT JOIN orders o ON o.id = so.order_id
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             WHERE sh.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function forSupplierOrderOrCreate(int $supplierOrderId): array
    {
        $existing = $this->forSupplierOrder($supplierOrderId);
        if ($existing !== null) {
            return $existing;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO shipments (supplier_order_id, status, created_at, updated_at) VALUES (?, "preparing", NOW(), NOW())'
        );
        $stmt->execute([$supplierOrderId]);
        $found = $this->forSupplierOrder($supplierOrderId);
        return $found ?? [];
    }

    /**
     * Kargo bilgisi güncelleme (admin/adapter). Tüm alanlar tek yerde.
     */
    public function updateShipment(
        int $id,
        string $status,
        ?string $carrier,
        ?string $trackingNumber,
        ?string $trackingUrl,
        ?string $note,
        ?string $shippedAt,
        ?string $deliveredAt
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE shipments SET
                status = ?,
                carrier = ?,
                tracking_number = ?,
                tracking_url = ?,
                note = ?,
                shipped_at = ?,
                delivered_at = ?,
                updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$status, $carrier, $trackingNumber, $trackingUrl, $note, $shippedAt, $deliveredAt, $id]);
    }

    /** Bir ana siparişin tüm gönderileri. @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sh.*, so.supplier_id, s.name AS supplier_name, o.order_number
             FROM shipments sh
             LEFT JOIN supplier_orders so ON so.id = sh.supplier_order_id
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             LEFT JOIN orders o ON o.id = so.order_id
             WHERE so.order_id = ? ORDER BY sh.id ASC'
        );
        $stmt->execute([$orderId]);
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    /** Admin listesi (durum/arama filtreli, sayfalı). @return array{rows:list<array<string,mixed>>, total:int} */
    public function adminList(string $status, string $search, int $page, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($status !== '' && $status !== 'all') {
            $where[] = 'sh.status = ?';
            $params[] = $status;
        }
        if ($search !== '') {
            $where[] = '(sh.tracking_number LIKE ? OR o.order_number LIKE ? OR sh.carrier LIKE ? OR so.internal_reference LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM shipments sh
             LEFT JOIN supplier_orders so ON so.id = sh.supplier_order_id
             LEFT JOIN orders o ON o.id = so.order_id
             WHERE $whereSql"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare(
            "SELECT sh.*, o.order_number, s.name AS supplier_name, so.internal_reference
             FROM shipments sh
             LEFT JOIN supplier_orders so ON so.id = sh.supplier_order_id
             LEFT JOIN orders o ON o.id = so.order_id
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             WHERE $whereSql
             ORDER BY sh.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /** Takip numarası girilmiş gönderiler (adapter senkronu). @return list<array<string,mixed>> */
    public function trackable(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sh.* FROM shipments sh
             WHERE sh.tracking_number IS NOT NULL AND sh.tracking_number <> ''
               AND sh.status IN ('shipped', 'in_transit')
             ORDER BY sh.id ASC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    /** Ana siparişin tüm gönderileri teslim edildi mi? */
    public function allDeliveredForOrder(int $orderId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM shipments sh
             JOIN supplier_orders so ON so.id = sh.supplier_order_id
             WHERE so.order_id = ? AND sh.status <> "delivered"'
        );
        $stmt->execute([$orderId]);
        $undelivered = (int) $stmt->fetchColumn();
        return $undelivered === 0 && $this->countForOrder($orderId) > 0;
    }

    /** Ana siparişin tüm gönderileri yolda/teslimde mi (kargoya verilmiş mi)? */
    public function allShippedForOrder(int $orderId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM shipments sh
             JOIN supplier_orders so ON so.id = sh.supplier_order_id
             WHERE so.order_id = ? AND sh.status NOT IN ("shipped", "in_transit", "delivered")'
        );
        $stmt->execute([$orderId]);
        $notShipped = (int) $stmt->fetchColumn();
        return $notShipped === 0 && $this->countForOrder($orderId) > 0;
    }

    public function countForOrder(int $orderId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM shipments sh JOIN supplier_orders so ON so.id = sh.supplier_order_id WHERE so.order_id = ?'
        );
        $stmt->execute([$orderId]);
        return (int) $stmt->fetchColumn();
    }
}
