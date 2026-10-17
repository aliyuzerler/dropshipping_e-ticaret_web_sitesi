<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\OrderRepository;
use App\Repositories\SupplierOrderRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Order\OrderService;
use App\Services\Order\OrderStatus;
use App\Services\Notification\NotificationService;
use Throwable;

/**
 * AŞAMA 5 — Tedarikçi siparişi işleme çekirdeği.
 *
 * TEKRARLI SİPARİŞ ÖNLEME (görev gereklilikleri):
 *  1) Her supplier_order BENZERSİZ dahili referansla doğar:
 *     SORD-{sipariş no}-S{tedarikçi id} — deterministik + UNIQUE index.
 *  2) API'ye idempotency anahtarı olarak bu referans gönderilir
 *     (gövde + Idempotency-Key başlığı).
 *  3) Gönderim iki aşamalıdır: tx-A içinde pending→sending (attempts++),
 *     HTTP çağrısı tx DIŞINDA, sonuç tx-B'de yazılır. Zaman aşımında/çökmede
 *     kayıt 'unknown' olur ve ASLA otomatik yeniden gönderilmez — önce
 *     durum sorgusu (queryStatus), cevap yoksa manuel inceleme.
 *  4) Ödemesi doğrulanmamış (paid ailesi dışındaki) siparişler işlenmez →
 *     "Ödeme başarısızsa tedarikçiye sipariş gönderme" garantisi.
 *  5) Mükerrer tedarikçi siparişi: UNIQUE(order_id, supplier_id) + INSERT IGNORE
 *     (Aşama 4) + gönderim yalnız 'pending' durumundan başlar (satır kilidi ile).
 *
 * Tam yol: app/Services/Supplier/SupplierOrderService.php
 */
final class SupplierOrderService
{
    private SupplierOrderRepository $supplierOrders;

    private OrderRepository $orders;

    private SupplierAdapterFactory $adapters;

    private ?OrderService $orderService;

    public function __construct(
        ?SupplierOrderRepository $supplierOrders = null,
        ?SupplierAdapterFactory $adapters = null,
        ?OrderRepository $orders = null,
        ?OrderService $orderService = null
    ) {
        $this->supplierOrders = $supplierOrders ?? new SupplierOrderRepository();
        $this->adapters       = $adapters ?? new SupplierAdapterFactory();
        $this->orders         = $orders ?? new OrderRepository();
        $this->orderService   = $orderService;
    }

    /** Deterministik, benzersiz dahili referans (idempotency anahtarı). */
    public static function internalReferenceFor(string $orderNumber, int $supplierId): string
    {
        return 'SORD-' . $orderNumber . '-S' . $supplierId;
    }

    /**
     * Bekleyen tedarikçi siparişlerini işler (cron/CLI + admin "gönder" aksiyonu).
     * API tedarikçileri gönderilir; manuel tedarikçiler panelde görev olarak kalır.
     *
     * @return array{sent:int, errors:int, unknown:int, manual:int, skipped:int}
     */
    public function processPending(?int $specificId = null, int $limit = 50, ?int $adminId = null, string $ip = ''): array
    {
        $ids = $specificId !== null ? [$specificId] : $this->supplierOrders->pendingSendIds($limit);

        $summary = ['sent' => 0, 'errors' => 0, 'unknown' => 0, 'manual' => 0, 'skipped' => 0];

        foreach ($ids as $id) {
            $result = $this->processOne((int) $id, $adminId, $ip);
            $summary[$result] = ($summary[$result] ?? 0) + 1;
        }
        return $summary;
    }

    /**
     * Tek bir tedarikçi siparişini gönderir. Dönüş: sent|errors|unknown|manual|skipped.
     */
    public function processOne(int $id, ?int $adminId = null, string $ip = ''): string
    {
        $pdo = Database::pdo();

        // ---- tx-A: satırı kilitle, 'sending'e geç (attempts++) ----
        $pdo->beginTransaction();
        try {
            $so = $this->supplierOrders->lockForProcessing($id);
            if ($so === null) {
                $pdo->commit();
                return 'skipped';
            }
            $status = (string) $so['status'];
            if ($status !== SupplierOrderStatus::PENDING) {
                // Kuyrukta bekleyen başka işleyici zaten almış olabilir → çift gönderim engeli.
                $pdo->commit();
                return 'skipped';
            }

            // Ödeme doğrulaması: ana sipariş paid ailesinde değilse gönderim YOK.
            $order = $this->orders->lockByIdForUpdate((int) $so['order_id']);
            if ($order === null || !in_array((string) $order['status'], SupplierOrderStatus::PAYMENT_VERIFIED_ORDER_STATUSES, true)) {
                $pdo->commit();
                Logger::info('app', 'supplier_order.skip_unpaid', [
                    'supplier_order_id' => $id,
                    'order_status'      => $order !== null ? (string) $order['status'] : 'missing',
                ]);
                return 'skipped'; // payment_failed/pending_payment/cancelled → ASLA gönderilmez
            }

            $this->supplierOrders->markSending($id);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error('app', 'supplier_order.lock_failed', ['id' => $id, 'reason' => $e->getMessage()]);
            return 'skipped';
        }

        // ---- Kilitler bırakıldı; tedarikçi + adaptör + HTTP çağrısı ----
        $so      = $this->supplierOrders->find($id);
        if ($so === null) {
            return 'skipped';
        }
        $supplier = [
            'id'                  => (int) $so['supplier_id'],
            'integration_type'    => $so['integration_type'],
            'api_endpoint'        => $so['api_endpoint'],
            'api_auth'            => $so['api_auth'],
            'api_credentials_enc' => $so['api_credentials_enc'],
        ];
        $adapter = $this->adapters->forSupplier($supplier);

        // Manuel tedarikçi: otomatik gönderim YOK → panelde bekleyen görev olarak kalır.
        if (!$adapter->supportsApi()) {
            $this->supplierOrders->applySendResult($id, SupplierOrderStatus::PENDING, null, 'Manuel tedarikçi — panelde iletilmeli.', null);
            return 'manual';
        }

        $items   = $this->supplierOrders->items($id);
        $context = $this->buildSendContext($so);

        $result = $adapter->sendOrder($so, $items, $context);

        // ---- tx-B: sonucu uygula ----
        $outcome = 'errors';
        if ($result['ok']) {
            $outcome = 'sent';
            $this->supplierOrders->applySendResult($id, SupplierOrderStatus::SENT, $result['external_reference'], null, 'api');
        } elseif ($result['timeout']) {
            // Zaman aşımı: karşı tarafta sipariş oluşmuş OLABİLİR → unknown (asla otomatik resend).
            $outcome = 'unknown';
            $this->supplierOrders->applySendResult($id, SupplierOrderStatus::UNKNOWN, null, 'Zaman aşımı — tedarikçide sipariş durumu belirsiz. Durum sorgusu/manuel inceleme gerekir.', null);
        } else {
            $this->supplierOrders->applySendResult($id, SupplierOrderStatus::ERROR, null, mb_substr($result['message'], 0, 500, 'UTF-8'), null);
        }

        $actor = $adminId !== null ? 'admin' : 'system';
        AuditLogger::log('supplier_order.send_' . $outcome, $adminId, $ip, '', 'supplier_order', $id, [
            'supplier_id'   => (int) $so['supplier_id'],
            'order_number'  => (string) $so['order_number'],
            'internal_ref'  => (string) $so['internal_reference'],
            'external_ref'  => $result['external_reference'],
            'timeout'       => $result['timeout'],
        ], $actor);

        if ($outcome === 'sent') {
            NotificationService::supplierOrderSent($so, $result['external_reference']);
        }
        Logger::info('app', 'supplier_order.processed', [
            'id' => $id, 'outcome' => $outcome, 'timeout' => $result['timeout'],
        ]);

        $this->syncOrderSupplierPending((int) $so['order_id']);
        return $outcome;
    }

    /**
     * Durum sorgusu: unknown/sending (ve istenirse belirli id) kayıtların tedarikçi
     * tarafındaki durumunu sorar. Yeniden gönderim YAPMAZ.
     *
     * @return array{queried:int, confirmed:int, not_found:int, failed:int}
     */
    public function queryStatuses(?int $specificId = null, int $limit = 50, ?int $adminId = null, string $ip = ''): array
    {
        $ids     = $this->supplierOrders->queryableIds($specificId, $limit);
        $summary = ['queried' => 0, 'confirmed' => 0, 'not_found' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $so = $this->supplierOrders->find((int) $id);
            if ($so === null) {
                continue;
            }
            $supplier = [
                'integration_type'    => $so['integration_type'],
                'api_endpoint'        => $so['api_endpoint'],
                'api_auth'            => $so['api_auth'],
                'api_credentials_enc' => $so['api_credentials_enc'],
            ];
            $adapter = $this->adapters->forSupplier($supplier);
            if (!$adapter->supportsApi()) {
                continue;
            }
            $res = $adapter->queryStatus($so);
            $summary['queried']++;

            if (!$res['ok']) {
                $this->supplierOrders->applyQueryResult((int) $id, (string) $so['status'], 'Durum sorgusu başarısız: ' . mb_substr($res['message'], 0, 300, 'UTF-8'), null);
                $summary['failed']++;
                continue;
            }
            if ($res['confirmed']) {
                $this->supplierOrders->applyQueryResult((int) $id, SupplierOrderStatus::CONFIRMED, null, $res['external_reference']);
                $summary['confirmed']++;
            } elseif ($res['found']) {
                // Tedarikçide kayıt var ama henüz onaylanmamış → gönderilmiş sayılır.
                $this->supplierOrders->applyQueryResult((int) $id, SupplierOrderStatus::SENT, null, $res['external_reference']);
            } else {
                // Tedarikçide kayıt YOK: unknown kalır, manuel inceleme için işaretlenir.
                $this->supplierOrders->applyQueryResult((int) $id, SupplierOrderStatus::UNKNOWN, 'Durum sorgusu: tedarikçide kayıt bulunamadı — Manuel inceleme gerekir.', null);
                $summary['not_found']++;
            }

            // Durum netleşti → ana siparişin supplier_pending eşitlemesi (idempotent)
            $this->syncOrderSupplierPending((int) $so['order_id']);

            AuditLogger::log('supplier_order.status_queried', $adminId, $ip, '', 'supplier_order', (int) $id, [
                'confirmed' => $res['confirmed'],
                'found'     => $res['found'],
                'message'   => mb_substr($res['message'], 0, 200, 'UTF-8'),
            ], $adminId !== null ? 'admin' : 'system');
        }
        return $summary;
    }

    /** Admin: manuel gönderim işaretleme (API olmayan tedarikçiler için ana yol). */
    public function markSentManually(int $id, ?string $externalReference, ?string $note, int $adminId, string $ip): void
    {
        $so = $this->supplierOrders->find($id);
        if ($so === null) {
            throw new \App\Exceptions\ValidationException(['supplier_order' => ['Tedarikçi siparişi bulunamadı.']]);
        }
        if (in_array((string) $so['status'], [SupplierOrderStatus::SENT, SupplierOrderStatus::CONFIRMED], true)) {
            return; // idempotent — zaten gönderildi
        }
        $this->supplierOrders->markSentManually($id, $externalReference !== '' ? $externalReference : null, $note);

        AuditLogger::log('supplier_order.sent_manually', $adminId, $ip, '', 'supplier_order', $id, [
            'order_number'  => (string) $so['order_number'],
            'supplier_id'   => (int) $so['supplier_id'],
            'external_ref'  => $externalReference,
        ], 'admin');

        NotificationService::supplierOrderSent($so, $externalReference);
        $this->syncOrderSupplierPending((int) $so['order_id']);
    }

    /** Admin: hatalı/belirsiz kaydı yeniden gönderim kuyruğuna alır (bilinçli karar gerekir). */
    public function retry(int $id, int $adminId, string $ip): void
    {
        $so = $this->supplierOrders->find($id);
        if ($so === null) {
            throw new \App\Exceptions\ValidationException(['supplier_order' => ['Tedarikçi siparişi bulunamadı.']]);
        }
        if ($this->supplierOrders->resetForRetry($id)) {
            AuditLogger::log('supplier_order.retry_queued', $adminId, $ip, '', 'supplier_order', $id, [
                'order_number' => (string) $so['order_number'],
            ], 'admin');
        }
    }

    /**
     * Ana siparişin tüm tedarikçi siparişleri iletildiyse siparişi
     * paid/processing → supplier_pending'e geçirir (idempotent).
     */
    public function syncOrderSupplierPending(int $orderId): void
    {
        try {
            if (!$this->supplierOrders->allDeliveredToSupplier($orderId)) {
                return;
            }
            $order = $this->orders->find($orderId);
            if ($order === null) {
                return;
            }
            $status = (string) $order['status'];
            if (in_array($status, [OrderStatus::PAID, OrderStatus::PROCESSING], true)) {
                $this->orderServiceOrNew()->transition($orderId, OrderStatus::SUPPLIER_PENDING, 'system', null, '', [
                    'reason' => 'all_supplier_orders_sent',
                ]);
            }
        } catch (Throwable $e) {
            // Geçiş reddi (ör. iade sürecinde) akışı bozmaz.
            Logger::info('app', 'supplier_order.supplier_pending_skip', [
                'order_id' => $orderId, 'reason' => $e->getMessage(),
            ]);
        }
    }

    /** Tedarikçi siparişi payload bağlamı (teslimat snapshot'ı + sipariş bilgileri). */
    private function buildSendContext(array $so): array
    {
        $delivery = [];
        if (!empty($so['delivery_snapshot'])) {
            /** @var array<string,mixed>|null $decoded */
            $decoded = json_decode((string) $so['delivery_snapshot'], true);
            if (is_array($decoded)) {
                // Teslimat snapshot'ından yalnız sevkiyat için gereken alanlar paylaşılır.
                $delivery = [
                    'full_name'   => $decoded['full_name'] ?? null,
                    'city'        => $decoded['city'] ?? null,
                    'district'    => $decoded['district'] ?? null,
                    'postal_code' => $decoded['postal_code'] ?? null,
                    'line1'       => $decoded['line1'] ?? null,
                    'line2'       => $decoded['line2'] ?? null,
                    'phone'       => $decoded['phone'] ?? null,
                ];
            }
        }
        return [
            'order_number' => (string) $so['order_number'],
            'currency'     => (string) ($so['currency'] ?? 'TRY'),
            'placed_at'    => (string) ($so['placed_at'] ?? ''),
            'note'         => (string) ($so['order_note'] ?? ''),
            'delivery'     => $delivery,
        ];
    }

    private function orderServiceOrNew(): OrderService
    {
        return $this->orderService ??= new OrderService($this->orders, $this->supplierOrders);
    }
}
