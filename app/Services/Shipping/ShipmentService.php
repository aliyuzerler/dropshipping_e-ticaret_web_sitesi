<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Database;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Repositories\ShipmentRepository;
use App\Repositories\SupplierOrderRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Notification\NotificationService;
use App\Services\Order\OrderService;
use App\Services\Order\OrderStatus;
use Throwable;

/**
 * AŞAMA 5 — Kargo yönetimi çekirdeği.
 *
 *  - Tedarikçi siparişi başına TEK gönderi (forSupplierOrderOrCreate).
 *  - Manuel takip numarası girişi (varsayılan) veya gerçek API adaptörü (varsa).
 *  - Gönderi durum geçişleri doğrulanır (ShipmentStatus::can).
 *  - Ana sipariş durumu otomatik eşitlenir:
 *      tüm gönderiler yolda → supplier_pending/processing → shipped
 *      tüm gönderiler teslim → → delivered
 *  - Bildirimler dedup anahtarıyla kuyruğa yazılır (tekrarlı gönderim imkânsız).
 *
 * Tam yol: app/Services/Shipping/ShipmentService.php
 */
final class ShipmentService
{
    private ShipmentRepository $shipments;

    private SupplierOrderRepository $supplierOrders;

    private ?OrderService $orderService;

    public function __construct(
        ?ShipmentRepository $shipments = null,
        ?SupplierOrderRepository $supplierOrders = null,
        ?OrderService $orderService = null
    ) {
        $this->shipments      = $shipments ?? new ShipmentRepository();
        $this->supplierOrders = $supplierOrders ?? new SupplierOrderRepository();
        $this->orderService   = $orderService;
    }

    /**
     * Kargo bilgisi güncelleme (admin kargo düzenleme ekranı).
     *
     * @param array<string,mixed> $input carrier, tracking_number, tracking_url, status, note
     * @return array<string,mixed> güncellenen shipments satırı
     */
    public function updateForSupplierOrder(int $supplierOrderId, array $input, int $adminId, string $ip): array
    {
        $so = $this->supplierOrders->find($supplierOrderId);
        if ($so === null) {
            throw new ValidationException(['shipment' => ['Tedarikçi siparişi bulunamadı.']]);
        }

        $shipment = $this->shipments->forSupplierOrderOrCreate($supplierOrderId);

        $status = trim((string) ($input['status'] ?? ''));
        if (!in_array($status, ShipmentStatus::ALL, true)) {
            throw new ValidationException(['status' => ['Geçersiz gönderi durumu.']]);
        }
        if (!ShipmentStatus::can((string) $shipment['status'], $status)) {
            throw new ValidationException(['status' => [
                'Geçersiz geçiş: ' . ShipmentStatus::label((string) $shipment['status']) . ' → ' . ShipmentStatus::label($status),
            ]]);
        }

        $carrier  = trim((string) ($input['carrier'] ?? '')) ?: (($shipment['carrier'] ?? null) !== null ? (string) $shipment['carrier'] : null);
        $tracking = trim((string) ($input['tracking_number'] ?? '')) ?: (($shipment['tracking_number'] ?? null) !== null ? (string) $shipment['tracking_number'] : null);
        $url      = trim((string) ($input['tracking_url'] ?? '')) ?: (($shipment['tracking_url'] ?? null) !== null ? (string) $shipment['tracking_url'] : null);
        $note     = trim((string) ($input['note'] ?? '')) ?: (($shipment['note'] ?? null) !== null ? (string) $shipment['note'] : null);

        // Kargoya verildi/teslim durumunda takip bilgisi zorunlu (manuel giriş dahil).
        if (in_array($status, [ShipmentStatus::SHIPPED, ShipmentStatus::IN_TRANSIT, ShipmentStatus::DELIVERED], true) && ($tracking === null || $carrier === null)) {
            throw new ValidationException(['tracking_number' => [
                'Kargoya verilen gönderide kargo firması ve takip numarası zorunludur.',
            ]]);
        }
        if ($tracking !== null && !preg_match('/^[A-Za-z0-9\-\._]{4,100}$/', $tracking)) {
            throw new ValidationException(['tracking_number' => ['Takip numarası biçimi geçersiz (4-100; harf/rakam/-/._).']]);
        }
        if ($url !== null && !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ValidationException(['tracking_url' => ['Takip bağlantısı geçerli bir URL olmalıdır.']]);
        }

        // (carrier, tracking) benzersizliği — başka gönderiye tanımlıysa reddet.
        if ($tracking !== null && $carrier !== null) {
            $dupe = $this->findByCarrierTracking($carrier, $tracking);
            if ($dupe !== null && (int) $dupe['id'] !== (int) $shipment['id']) {
                throw new ValidationException(['tracking_number' => ['Bu takip numarası başka bir gönderide kayıtlı.']]);
            }
        }

        $shippedAt   = (string) $shipment['shipped_at'];
        $deliveredAt = (string) $shipment['delivered_at'];
        if ($status === ShipmentStatus::SHIPPED && $shippedAt === '') {
            $shippedAt = date('Y-m-d H:i:s');
        }
        if ($status === ShipmentStatus::DELIVERED) {
            if ($shippedAt === '') {
                $shippedAt = date('Y-m-d H:i:s');
            }
            if ($deliveredAt === '') {
                $deliveredAt = date('Y-m-d H:i:s');
            }
        }

        $this->shipments->updateShipment((int) $shipment['id'], $status, $carrier, $tracking, $url, $note, $shippedAt !== '' ? $shippedAt : null, $deliveredAt !== '' ? $deliveredAt : null);

        AuditLogger::log('shipment.updated', $adminId, $ip, '', 'shipment', (int) $shipment['id'], [
            'supplier_order_id' => $supplierOrderId,
            'order_number'      => (string) $so['order_number'],
            'from'              => (string) $shipment['status'],
            'to'                => $status,
            'carrier'           => $carrier,
            'tracking_number'   => $tracking,
        ], 'admin');

        Logger::info('app', 'shipment.updated', [
            'shipment_id' => (int) $shipment['id'],
            'order'       => (string) $so['order_number'],
            'status'      => $status,
        ]);

        $orderState = $this->syncOrderShipping((int) $so['order_id']);

        return $this->shipments->forSupplierOrder($supplierOrderId) ?? $shipment + ['status' => $status, 'order_state' => $orderState];
    }

    /**
     * Ana siparişin kargo durumunu eşitler + bildirimleri kuyruğa yazar.
     * @return string none|shipped|delivered (yapılan geçiş)
     */
    public function syncOrderShipping(int $orderId): string
    {
        try {
            $order = (new \App\Repositories\OrderRepository())->find($orderId);
            if ($order === null) {
                return 'none';
            }
            $status = (string) $order['status'];

            $allShipped   = $this->shipments->allShippedForOrder($orderId);
            $allDelivered = $this->shipments->allDeliveredForOrder($orderId);

            if ($allDelivered && in_array($status, [OrderStatus::SHIPPED, OrderStatus::PROCESSING, OrderStatus::SUPPLIER_PENDING], true)) {
                $this->orderServiceOrNew()->transition($orderId, OrderStatus::DELIVERED, 'system', null, '', ['reason' => 'all_shipments_delivered']);
                NotificationService::orderDelivered($order);
                return 'delivered';
            }
            if ($allShipped && in_array($status, [OrderStatus::PROCESSING, OrderStatus::SUPPLIER_PENDING], true)) {
                $this->orderServiceOrNew()->transition($orderId, OrderStatus::SHIPPED, 'system', null, '', ['reason' => 'all_shipments_shipped']);
                NotificationService::orderShipped($order, $this->shipments->forOrder($orderId));
                return 'shipped';
            }
            // Kısmi kargo (bir tedarikçi gönderdi) → yine müşteriye haber ver (tek kez).
            if ($status === OrderStatus::SUPPLIER_PENDING && $this->shipments->countForOrder($orderId) > 0) {
                $anyShipped = $this->shipments->forOrder($orderId);
                $shippedAny = array_filter($anyShipped, static fn (array $s): bool => in_array((string) $s['status'], [ShipmentStatus::SHIPPED, ShipmentStatus::IN_TRANSIT, ShipmentStatus::DELIVERED], true));
                if ($shippedAny !== []) {
                    NotificationService::orderShipped($order, array_values($shippedAny));
                }
            }
            return 'none';
        } catch (Throwable $e) {
            Logger::info('app', 'shipment.order_sync_skip', ['order_id' => $orderId, 'reason' => $e->getMessage()]);
            return 'none';
        }
    }

    /**
     * Kargo takip senkronu (cron): takip numarası olan gönderiler adaptörden sorgulanır.
     * API yoksa (manuel adaptör) hiçbir şey yapmaz — bu bilinçli davranıştır.
     * @return array{checked:int, updated:int}
     */
    public function syncFromAdapter(int $limit = 50, ?int $adminId = null): array
    {
        $adapter = ShippingAdapterFactory::resolve();
        $checked = 0;
        $updated = 0;
        foreach ($this->shipments->trackable($limit) as $shipment) {
            $checked++;
            $res = $adapter->track($shipment);
            if (!$res['ok']) {
                continue; // manuel mod: sessizce geçilir
            }
            $newStatus = $this->mapAdapterStatus($res['status'], (bool) $res['delivered']);
            if ($newStatus !== null && ShipmentStatus::can((string) $shipment['status'], $newStatus) && $newStatus !== (string) $shipment['status']) {
                $this->shipments->updateShipment(
                    (int) $shipment['id'],
                    $newStatus,
                    (string) $shipment['carrier'],
                    (string) $shipment['tracking_number'],
                    (string) $shipment['tracking_url'],
                    (string) $shipment['note'],
                    (string) $shipment['shipped_at'],
                    $newStatus === ShipmentStatus::DELIVERED && $shipment['delivered_at'] === null ? date('Y-m-d H:i:s') : $shipment['delivered_at']
                );
                $updated++;
                if (!empty($shipment['order_id'])) {
                    $this->syncOrderShipping((int) $shipment['order_id']);
                }
            }
        }
        Logger::info('app', 'shipment.adapter_sync', ['checked' => $checked, 'updated' => $updated]);
        return ['checked' => $checked, 'updated' => $updated];
    }

    /** Kargo firması ham durumunu gönderi durumuna eşler (adaptör entegrasyonu için). */
    private function mapAdapterStatus(?string $rawStatus, bool $delivered): ?string
    {
        if ($delivered) {
            return ShipmentStatus::DELIVERED;
        }
        $s = mb_strtolower(trim((string) $rawStatus));
        return match (true) {
            in_array($s, ['delivered', 'teslim'], true)      => ShipmentStatus::DELIVERED,
            in_array($s, ['in_transit', 'transit', 'yolda'], true) => ShipmentStatus::IN_TRANSIT,
            in_array($s, ['shipped', 'kargo_birimi'], true)  => ShipmentStatus::SHIPPED,
            in_array($s, ['exception', 'sorun'], true)       => ShipmentStatus::EXCEPTION,
            in_array($s, ['returned', 'iade'], true)         => ShipmentStatus::RETURNED,
            default                                          => null,
        };
    }

    private function findByCarrierTracking(string $carrier, string $tracking): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM shipments WHERE carrier = ? AND tracking_number = ? LIMIT 1'
        );
        $stmt->execute([$carrier, $tracking]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        return $this->shipments->find((int) $id);
    }

    private function orderServiceOrNew(): OrderService
    {
        return $this->orderService ??= new OrderService();
    }
}
