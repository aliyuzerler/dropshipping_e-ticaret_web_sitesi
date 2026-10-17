<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * supplier_orders + supplier_order_items erişimi (AŞAMA 4).
 * UNIQUE(order_id, supplier_id) → "aynı sipariş için iki kez tedarikçi siparişi"
 * engellemesi veritabanı düzeyinde garanti edilir.
 *
 * Tam yol: app/Repositories/SupplierOrderRepository.php
 */
final class SupplierOrderRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'supplier_orders';
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT so.*, s.name AS supplier_name FROM supplier_orders so
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             WHERE so.order_id = ? ORDER BY so.id ASC'
        );
        $stmt->execute([$orderId]);
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    /** Sipariş için tedarikçi siparişi var mı? */
    public function existsForOrder(int $orderId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM supplier_orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Tedarikçi siparişi oluşturur (zaten varsa sessizce atlar — INSERT IGNORE).
     * AŞAMA 5: deterministik dahili referans + teslimat snapshot'ı ile birlikte.
     *
     * @return bool true = yeni oluşturuldu, false = zaten vardı
     */
    public function createOnce(int $orderId, int $supplierId, string $internalReference, ?string $deliverySnapshotJson = null): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO supplier_orders
             (order_id, supplier_id, internal_reference, delivery_snapshot, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, "pending", NOW(), NOW())'
        );
        $stmt->execute([$orderId, $supplierId, $internalReference, $deliverySnapshotJson]);
        return $stmt->rowCount() > 0;
    }

    /** Sipariş kalemlerini tedarikçi sipariş kalemlerine yazar (snapshot). */
    public function createItems(int $supplierOrderId, array $items): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO supplier_order_items
             (supplier_order_id, order_item_id, product_id, supplier_sku, quantity, unit_cost, line_total, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        foreach ($items as $item) {
            $stmt->execute([
                $supplierOrderId,
                $item['order_item_id'],
                $item['product_id'] ?? null,
                $item['sku_snapshot'],
                $item['quantity'],
                $item['unit_cost'],
                $item['line_total'],
            ]);
        }
    }

    /** Tedarikçi siparişi id'si (order+supplier). */
    public function idFor(int $orderId, int $supplierId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM supplier_orders WHERE order_id = ? AND supplier_id = ? LIMIT 1');
        $stmt->execute([$orderId, $supplierId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /* ---------- AŞAMA 5 ---------- */

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT so.*, s.name AS supplier_name, s.code AS supplier_code, s.integration_type,
                    s.api_endpoint, s.api_auth, s.api_credentials_enc,
                    o.order_number, o.status AS order_status, o.grand_total, o.currency, o.note AS order_note,
                    o.shipping_snapshot, o.placed_at
             FROM supplier_orders so
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             LEFT JOIN orders o ON o.id = so.order_id
             WHERE so.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByInternalReference(string $internalReference): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_orders WHERE internal_reference = ? LIMIT 1');
        $stmt->execute([$internalReference]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function items(int $supplierOrderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT soi.*, oi.product_name_snapshot, oi.tax_rate
             FROM supplier_order_items soi
             LEFT JOIN order_items oi ON oi.id = soi.order_item_id
             WHERE soi.supplier_order_id = ? ORDER BY soi.id ASC'
        );
        $stmt->execute([$supplierOrderId]);
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    /**
     * Admin listesi — filtre: all|pending|sending|sent|confirmed|error|unknown|cancelled,
     * supplier_id, arama (sipariş no / dahili referans / dış referans). Sayfalı.
     * @return array{rows:list<array<string,mixed>>, total:int}
     */
    public function adminList(string $filter, ?int $supplierId, string $search, int $page, int $perPage = 20): array
    {
        $where  = ['1=1'];
        $params = [];
        if ($filter !== 'all' && $filter !== '') {
            $where[] = 'so.status = ?';
            $params[] = $filter;
        }
        if ($supplierId !== null && $supplierId > 0) {
            $where[] = 'so.supplier_id = ?';
            $params[] = $supplierId;
        }
        if ($search !== '') {
            $where[] = '(o.order_number LIKE ? OR so.internal_reference LIKE ? OR so.external_reference LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM supplier_orders so LEFT JOIN orders o ON o.id = so.order_id WHERE $whereSql"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $stmt = $this->pdo->prepare(
            "SELECT so.*, s.name AS supplier_name, s.integration_type, o.order_number, o.status AS order_status,
                    o.grand_total, o.currency
             FROM supplier_orders so
             LEFT JOIN suppliers s ON s.id = so.supplier_id
             LEFT JOIN orders o ON o.id = so.order_id
             WHERE $whereSql
             ORDER BY so.id DESC
             LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return ['rows' => $rows, 'total' => $total];
    }

    /** Duruma göre tedarikçi siparişleri (CLI işleme kuyruğu için, satır kilitli okuma). */
    public function lockForProcessing(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM supplier_orders WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** İşlenecek (pending) tedarikçi siparişi id'leri — ana sipariş ÖDEMESİ DOĞRULANMIŞ olanlardan. */
    public function pendingSendIds(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT so.id FROM supplier_orders so
             JOIN orders o ON o.id = so.order_id
             WHERE so.status = "pending"
               AND o.status IN ("paid", "processing", "supplier_pending")
             ORDER BY o.placed_at ASC, so.id ASC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<int> $ids */
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        return $ids;
    }

    /**
     * Transit/"sending" süresi aşmış (süreç çökmüş) kayıtlar — otomatik yeniden
     * GÖNDERİLMEZ, yalnız durum sorgusu/manuel inceleme için toplanır.
     * @return list<int>
     */
    public function staleSendingIds(int $graceMinutes, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM supplier_orders
             WHERE status = "sending" AND updated_at < (NOW() - INTERVAL ? MINUTE)
             ORDER BY id ASC LIMIT ?'
        );
        $stmt->bindValue(1, max(1, $graceMinutes), \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<int> $ids */
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        return $ids;
    }

    /** Durum sorgulanacak kayıtlar: unknown/sending (ve istenirse sent→confirmed takibi). */
    public function queryableIds(?int $specificId = null, int $limit = 50): array
    {
        if ($specificId !== null) {
            return [$specificId];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id FROM supplier_orders WHERE status IN ("unknown", "sending") ORDER BY updated_at ASC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<int> $ids */
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        return $ids;
    }

    /**
     * Gönderim sonucu uygular (satır kilitli olmalı). $nextStatus ∈ sent|error|unknown|pending.
     */
    public function applySendResult(int $id, string $nextStatus, ?string $externalReference, ?string $errorMessage, ?string $sentBy): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE supplier_orders SET
                status = ?,
                external_reference = COALESCE(?, external_reference),
                error_message = ?,
                sent_by = ?,
                sent_at = IF(? = "sent" AND sent_at IS NULL, NOW(), sent_at),
                status_updated_at = NOW(),
                updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $nextStatus,
            $externalReference,
            $errorMessage,
            $sentBy,
            $nextStatus,
            $id,
        ]);
    }

    /** Gönderim denemesi başlarken: attempts++ + transit durum (satır kilitli). */
    public function markSending(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE supplier_orders SET status = "sending", attempts = attempts + 1, status_updated_at = NOW(), updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    /** Durum sorgusu sonucu: confirmed|sent|error ve mesaj. */
    public function applyQueryResult(int $id, string $nextStatus, ?string $message, ?string $externalReference): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE supplier_orders SET
                status = ?,
                external_reference = COALESCE(?, external_reference),
                error_message = ?,
                status_updated_at = NOW(),
                updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$nextStatus, $externalReference, $message, $id]);
    }

    /** Manuel gönderim işaretleme (admin). */
    public function markSentManually(int $id, ?string $externalReference, ?string $note): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE supplier_orders SET
                status = "sent",
                sent_by = "manual",
                external_reference = COALESCE(?, external_reference),
                note = COALESCE(?, note),
                error_message = NULL,
                sent_at = IFNULL(sent_at, NOW()),
                status_updated_at = NOW(),
                updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$externalReference, $note, $id]);
    }

    /** Hatalı kaydı yeniden deneme kuyruğuna alır (error/unknown → pending). */
    public function resetForRetry(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE supplier_orders SET status = "pending", error_message = NULL, status_updated_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status IN ("error", "unknown")'
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Ana sipariş iptalinde gönderilmemiş tedarikçi siparişlerini iptal eder. @return int */
    public function cancelUnsentForOrder(int $orderId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE supplier_orders SET status = "cancelled", error_message = "Ana sipariş iptal edildi.", status_updated_at = NOW(), updated_at = NOW()
             WHERE order_id = ? AND status IN ("pending", "sending", "error")'
        );
        $stmt->execute([$orderId]);
        return $stmt->rowCount();
    }

    /** Ana siparişin tüm tedarikçi siparişleri iletildi mi? (sipariş → supplier_pending) */
    public function allDeliveredToSupplier(int $orderId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM supplier_orders
             WHERE order_id = ? AND status NOT IN ("sent", "confirmed")'
        );
        $stmt->execute([$orderId]);
        return (int) $stmt->fetchColumn() === 0;
    }

    /** Ana siparişin tedarikçi siparişi var mı? */
    public function countForOrder(int $orderId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM supplier_orders WHERE order_id = ?');
        $stmt->execute([$orderId]);
        return (int) $stmt->fetchColumn();
    }

    /** Durum kırılımı (admin özet/rapor). @return array<string,int> */
    public function statusCounts(?int $supplierId = null): array
    {
        $sql = 'SELECT status, COUNT(*) AS c FROM supplier_orders';
        $params = [];
        if ($supplierId !== null) {
            $sql .= ' WHERE supplier_id = ?';
            $params[] = $supplierId;
        }
        $sql .= ' GROUP BY status';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * Tedarikçi bazında sipariş raporu (AŞAMA 5): adet/durum/maliyet/ort. süreler.
     * @return list<array<string,mixed>>
     */
    public function supplierReport(): array
    {
        $sql = "SELECT s.id, s.name, s.code, s.integration_type,
                    COUNT(so.id) AS total_orders,
                    SUM(so.status = 'pending') AS pending_count,
                    SUM(so.status IN ('sent','confirmed')) AS sent_count,
                    SUM(so.status = 'error') AS error_count,
                    SUM(so.status = 'unknown') AS unknown_count,
                    SUM(so.status = 'cancelled') AS cancelled_count,
                    COALESCE(SUM(CASE WHEN so.status IN ('sent','confirmed') THEN soi_cost.total END), 0) AS purchase_cost,
                    AVG(CASE WHEN so.sent_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, so.created_at, so.sent_at) END) AS avg_send_minutes
                FROM suppliers s
                LEFT JOIN supplier_orders so ON so.supplier_id = s.id
                LEFT JOIN (
                    SELECT supplier_order_id, SUM(line_total) AS total
                    FROM supplier_order_items GROUP BY supplier_order_id
                ) soi_cost ON soi_cost.supplier_order_id = so.id
                GROUP BY s.id, s.name, s.code, s.integration_type
                ORDER BY total_orders DESC, s.name ASC";
        $stmt = $this->pdo->query($sql);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        return $rows;
    }
}
