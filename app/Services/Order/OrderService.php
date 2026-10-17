<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\OrderRepository;
use App\Repositories\SupplierOrderRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\NotificationService;
use App\Services\Supplier\SupplierOrderService;
use Throwable;

/**
 * AŞAMA 4 — Sipariş durumu yönetimi (durum makinesi uygulayıcısı).
 *
 * İlkeler:
 *  - Tüm durum değişiklikleri transition() üzerinden yapılır; tanımsız geçiş
 *    ValidationException ile REDDEDİLİR.
 *  - Ödeme sonucu işleme (markPaid) transaction + SELECT ... FOR UPDATE ile
 *    yapılır → aynı callback'in tekrar işlenmesi imkânsızdır.
 *  - Tedarikçi siparişi UNIQUE(order_id, supplier_id) + INSERT IGNORE ile
 *    yalnızca BİR KEZ oluşturulur.
 *  - Her değişiklik audit_logs'a yazılır (kim, ne zaman, eski→yeni).
 *
 * Tam yol: app/Services/Order/OrderService.php
 */
final class OrderService
{
    private OrderRepository $orders;

    private SupplierOrderRepository $supplierOrders;

    public function __construct(?OrderRepository $orders = null, ?SupplierOrderRepository $supplierOrders = null)
    {
        $this->orders         = $orders ?? new OrderRepository();
        $this->supplierOrders = $supplierOrders ?? new SupplierOrderRepository();
    }

    /**
     * Durum geçişi doğrular + uygular + loglar. Transaction KULLANMAZ (çağıranın
     * transaction'ı içinde veya tek başına çağrılabilir; satır kilidi burada alınır).
     *
     * @param array<string,mixed> $context audit bağlamı
     */
    public function transition(
        int $orderId,
        string $to,
        string $actorType = 'system',
        ?int $actorId = null,
        string $ip = '',
        array $context = []
    ): array {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if ($order === false) {
                throw new \App\Exceptions\ValidationException(['order' => ['Sipariş bulunamadı.']]);
            }

            $from = (string) $order['status'];
            if ($from === $to) {
                $pdo->commit();
                return $order; // no-op (idempotent çağrılar)
            }
            if (!OrderStatus::can($from, $to)) {
                throw new \App\Exceptions\ValidationException(['order' => [
                    'Geçersiz durum geçişi: ' . OrderStatus::label($from) . ' → ' . OrderStatus::label($to),
                ]]);
            }

            $upd = $pdo->prepare(
                'UPDATE orders SET status = ?, paid_at = IF(? = "paid" AND paid_at IS NULL, NOW(), paid_at), updated_at = NOW() WHERE id = ?'
            );
            $upd->execute([$to, $to, $orderId]);

            AuditLogger::log(
                'order.status_changed',
                $actorType === 'admin' ? $actorId : null,
                $ip,
                '',
                'order',
                $orderId,
                ['from' => $from, 'to' => $to] + $context,
                $actorType
            );

            // AŞAMA 5: iptalde gönderilmemiş tedarikçi siparişleri de iptal edilir
            // (ödenmiş siparişin gönderilmiş kalemlerine dokunulmaz — iade akışı işler).
            if ($to === OrderStatus::CANCELLED) {
                $this->supplierOrders->cancelUnsentForOrder($orderId);
            }

            $order['status'] = $to;
            $pdo->commit();
            Logger::info('app', 'order.status_changed', ['order_id' => $orderId, 'from' => $from, 'to' => $to]);

            // AŞAMA 5: iptal bildirimi (kuyruğa yazılır; gönderim kuyruk işlemcisiyle)
            if ($to === OrderStatus::CANCELLED) {
                NotificationService::orderCancelled(array_merge($order, ['status' => OrderStatus::CANCELLED]), (string) ($context['reason'] ?? ''));
            }
            return $order;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Ödeme başarıyla doğrulandığında siparişi 'paid' yapar (transaction İÇİNDE
     * çağrılmalı; order satırı FOR UPDATE ile kilitli olmalı).
     *
     * @param array<string,mixed> $orderLocked kilitli order satırı
     * @param array<string,mixed> $payment payments satırı
     * @param array<string,mixed> $detail  iyzico detail yanıtı
     */
    public function markPaidLocked(array $orderLocked, array $payment, array $detail): void
    {
        $pdo = Database::pdo();
        $orderId = (int) $orderLocked['id'];

        // 1) Sipariş → paid (idempotent: zaten paid ise dokunma)
        $status = (string) $orderLocked['status'];
        if ($status === OrderStatus::PENDING_PAYMENT || $status === OrderStatus::PAYMENT_FAILED) {
            $upd = $pdo->prepare(
                'UPDATE orders SET status = "paid", paid_at = IFNULL(paid_at, NOW()), updated_at = NOW() WHERE id = ?'
            );
            $upd->execute([$orderId]);
            AuditLogger::log(
                'order.status_changed',
                null,
                '',
                '',
                'order',
                $orderId,
                ['from' => $status, 'to' => OrderStatus::PAID, 'source' => 'payment.success', 'payment_id' => (int) $payment['id']],
                'system'
            );
        } elseif (!in_array($status, [OrderStatus::PAID, OrderStatus::PROCESSING, OrderStatus::SUPPLIER_PENDING, OrderStatus::SHIPPED, OrderStatus::DELIVERED, OrderStatus::REFUND_PENDING, OrderStatus::REFUNDED], true)) {
            throw new \App\Exceptions\ValidationException(['order' => [
                'Ödeme sonucu yazılamadı — sipariş durumu uygun değil: ' . $status,
            ]]);
        }

        // AŞAMA 5: ödeme onay bildirimi (INSERT IGNORE — dedup; tx rollback'te geri alınır)
        NotificationService::paymentConfirmed(array_merge($orderLocked, ['status' => OrderStatus::PAID]));

        // 2) Ödeme kaydını sonuçlandır (idempotent — zaten success ise güncelleme zararsız)
        $payUpd = $pdo->prepare(
            'UPDATE payments SET
                status = "success",
                provider_payment_id = IFNULL(provider_payment_id, ?),
                amount = ?,
                installment = ?,
                card_last_four = IFNULL(card_last_four, ?),
                card_association = IFNULL(card_association, ?),
                completed_at = IFNULL(completed_at, NOW()),
                updated_at = NOW()
             WHERE id = ?'
        );
        $payUpd->execute([
            (string) ($detail['paymentId'] ?? ''),
            (float) ($detail['paidPrice'] ?? $payment['amount']),
            max(1, (int) ($detail['installment'] ?? 1)),
            substr((string) ($detail['lastFourDigits'] ?? ''), 0, 4) ?: null,
            (string) ($detail['cardAssociation'] ?? '') ?: null,
            (int) $payment['id'],
        ]);

        // 3) Tedarikçi siparişleri (UNIQUE(order_id, supplier_id) — yalnız bir kez)
        $this->createSupplierOrdersLocked($orderId);
    }

    /**
     * Siparişin tedarikçi siparişlerini oluşturur (kilitli transaction içinde).
     * Her (order, supplier) çifti için INSERT IGNORE → çift kayıt imkânsız.
     *
     * AŞAMA 5 uzantıları (Aşama 4 davranışı KORUNUR, yalnız zenginleştirilir):
     *  - supplier_orders.internal_reference → deterministik, benzersiz idempotency anahtarı
     *  - supplier_orders.delivery_snapshot  → tedarikçinin sevkiyata ihtiyaç duyduğu teslimat bilgisi
     *  - supplier_order_items.supplier_sku  → öncelik tedarikçinin KENDİ kodu (psd.supplier_sku)
     *  - supplier_order_items.unit_cost     → ALIŞ maliyeti (psd.supplier_price); yoksa satış fiyatı snapshot'ı
     *
     * @return int yeni oluşturulan tedarikçi siparişi sayısı
     */
    public function createSupplierOrdersLocked(int $orderId): int
    {
        $pdo   = Database::pdo();
        $stmt  = $pdo->prepare(
            'SELECT id, product_id, supplier_id, product_name_snapshot, sku_snapshot, quantity, unit_price, line_total
             FROM order_items WHERE order_id = ? AND supplier_id IS NOT NULL'
        );
        $stmt->execute([$orderId]);
        /** @var list<array<string,mixed>> $items */
        $items = $stmt->fetchAll();

        $bySupplier = [];
        foreach ($items as $item) {
            $bySupplier[(int) $item['supplier_id']][] = $item;
        }

        // Teslimat snapshot'ı (tedarikçi sevkiyatı için) — ana siparişten alınır
        $shipStmt = $pdo->prepare('SELECT order_number, shipping_snapshot, currency, placed_at, note FROM orders WHERE id = ? LIMIT 1');
        $shipStmt->execute([$orderId]);
        $orderRow = $shipStmt->fetch() ?: [];
        $deliverySnapshot = null;
        if (isset($orderRow['shipping_snapshot']) && is_string($orderRow['shipping_snapshot']) && $orderRow['shipping_snapshot'] !== '') {
            $deliverySnapshot = $orderRow['shipping_snapshot'];
        }

        // Tedarikçi ürün kodu + ALIŞ fiyatı eşlemesi (product_supplier_data)
        $productIds = array_values(array_unique(array_filter(array_map(
            static fn (array $i): int => (int) ($i['product_id'] ?? 0),
            $items
        ), static fn (int $id): bool => $id > 0)));
        $psdMap = [];
        if ($productIds !== []) {
            $psdStmt = $pdo->prepare(
                'SELECT product_id, supplier_id, supplier_sku, supplier_price FROM product_supplier_data
                 WHERE product_id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ')'
            );
            $psdStmt->execute($productIds);
            foreach ($psdStmt->fetchAll() as $psd) {
                $psdMap[(int) $psd['product_id'] . ':' . (int) $psd['supplier_id']] = $psd;
            }
        }

        $created = 0;
        foreach ($bySupplier as $supplierId => $supplierItems) {
            $internalRef = SupplierOrderService::internalReferenceFor((string) ($orderRow['order_number'] ?? ('ORD-' . $orderId)), $supplierId);
            if (!$this->supplierOrders->createOnce($orderId, $supplierId, $internalRef, $deliverySnapshot)) {
                continue; // zaten var — çift tedarikçi siparişi engellendi
            }
            $created++;
            $supplierOrderId = (int) $this->supplierOrders->idFor($orderId, $supplierId);
            $rows = [];
            foreach ($supplierItems as $item) {
                $psd = $psdMap[(int) $item['product_id'] . ':' . $supplierId] ?? null;
                $supplierSku = $psd['supplier_sku'] ?? null;
                $unitCost = $psd['supplier_price'] !== null && (float) $psd['supplier_price'] > 0
                    ? (float) $psd['supplier_price']
                    : (float) $item['unit_price'];
                $rows[] = [
                    'order_item_id'         => (int) $item['id'],
                    'product_id'            => $item['product_id'] !== null ? (int) $item['product_id'] : null,
                    'sku_snapshot'          => (string) ($supplierSku ?? $item['sku_snapshot']),
                    'quantity'              => (int) $item['quantity'],
                    'unit_cost'             => $unitCost,
                    'line_total'            => (float) $item['line_total'],
                ];
            }
            $this->supplierOrders->createItems($supplierOrderId, $rows);
        }
        return $created;
    }

    /**
     * Süresi geçen pending_payment siparişlerini iptal eder (cron / CLI).
     * Stok geri ALINMAZ: stok tedarikçi XML senkronizasyonuyla tazeLENir
     * (dropshipping modelinde yerel artırım drift yaratır).
     * @return int iptal edilen sipariş sayısı
     */
    public function expireStalePending(int $minutes): int
    {
        $pdo   = Database::pdo();
        $stmt  = $pdo->prepare(
            'SELECT id, order_number FROM orders
             WHERE status = "pending_payment"
               AND placed_at < (NOW() - INTERVAL ? MINUTE)
             LIMIT 200'
        );
        $stmt->execute([max(5, $minutes)]);
        $expired = $stmt->fetchAll();

        $count = 0;
        foreach ($expired as $row) {
            try {
                $this->transition((int) $row['id'], OrderStatus::CANCELLED, 'system', null, '', [
                    'reason'    => 'order_expired',
                    'order_number' => (string) $row['order_number'],
                ]);
                $count++;
            } catch (Throwable $e) {
                Logger::error('app', 'order.expire_failed', [
                    'order_id' => (int) $row['id'],
                    'reason'   => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }
}
