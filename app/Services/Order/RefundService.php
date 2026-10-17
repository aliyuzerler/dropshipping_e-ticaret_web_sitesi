<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\IyzipayClientInterface;
use App\Services\Payment\IyzipayException;
use App\Services\Payment\PaymentService;
use Throwable;

/**
 * AŞAMA 4 — İptal ve iade modülü.
 *
 * Veri modeli (refunds tablosu):
 *  - type:  refund (iade) | cancel (ödeme iptali)
 *  - scope: full | partial
 *  - status: pending → approved değil → completed | failed | rejected
 *    (pending = talep; completed = iyzico işlemi başarılı; admin işlem logları audit_logs'ta)
 *
 * iyzico uçları (resmî docs: "İptal ve İade"):
 *  - İptal : POST /payment/cancel            {paymentId, conversationId, ip, reason}
 *  - İade  : POST /payment/refund            {paymentTransactionId, price, currency, ...}
 *    (kırılım bazlı: payments.raw_response → itemTransactions[].paymentTransactionId)
 *
 * Tam yol: app/Services/Order/RefundService.php
 */
final class RefundService
{
    private RefundRepository $refunds;

    private PaymentRepository $payments;

    private OrderRepository $orders;

    private OrderService $orderService;

    private IyzipayClientInterface $client;

    public function __construct(
        ?RefundRepository $refunds = null,
        ?PaymentRepository $payments = null,
        ?OrderRepository $orders = null,
        ?OrderService $orderService = null,
        ?IyzipayClientInterface $client = null
    ) {
        $this->refunds      = $refunds ?? new RefundRepository();
        $this->payments     = $payments ?? new PaymentRepository();
        $this->orders       = $orders ?? new OrderRepository();
        $this->orderService = $orderService ?? new OrderService();
        $this->client       = $client ?? PaymentService::defaultClient();
    }

    /**
     * MÜŞTERİ iade talebi: refunds satırı (pending) + sipariş refund_pending'e alınır.
     * Kısmi iade için sipariş kalemi (order_item) seçilmelidir → iyzico kırılımına
     * (paymentTransactionId) order_item.id ↔ itemTransactions.itemId eşlemesiyle bağlanır.
     *
     * @return int refunds id
     */
    public function requestByCustomer(
        string $orderNumber,
        int $customerId,
        ?float $amount,
        string $reason,
        string $ip,
        string $userAgent,
        ?int $orderItemId = null
    ): int {
        $order = $this->orders->findByNumberForCustomer($orderNumber, $customerId);
        if ($order === null) {
            throw new \App\Exceptions\ValidationException(['refund' => ['Sipariş bulunamadı.']]);
        }

        $payment = $this->payments->successForOrder((int) $order['id']);
        if ($payment === null) {
            throw new \App\Exceptions\ValidationException(['refund' => ['Bu sipariş için iade edilebilir bir ödeme bulunmuyor.']]);
        }

        $status = (string) $order['status'];
        if (!in_array($status, OrderStatus::refundRequestable(), true)) {
            throw new \App\Exceptions\ValidationException(['refund' => [
                'Bu durumdaki sipariş için iade talebi açılamaz (' . OrderStatus::label($status) . ').',
            ]]);
        }

        $refundedTotal = $this->refunds->refundedTotal((int) $order['id']);
        $grandTotal    = (float) $order['grand_total'];
        $remaining     = round($grandTotal - $refundedTotal, 2);
        if ($remaining <= 0) {
            throw new \App\Exceptions\ValidationException(['refund' => ['Siparişin tamamı zaten iade edildi.']]);
        }

        $isFull = ($amount === null || $amount <= 0 || bccomp((string) $amount, (string) $remaining, 2) === 0);
        $transactionId = null;
        if (!$isFull) {
            if ((float) $amount > $remaining) {
                throw new \App\Exceptions\ValidationException(['refund' => [
                    'Talep tutarı iade edilebilir tutarı aşıyor (en fazla ' . number_format($remaining, 2, ',', '.') . ' ₺).',
                ]]);
            }
            if ((float) $amount < 0.01) {
                throw new \App\Exceptions\ValidationException(['refund' => ['İade tutarı en az 0,01 ₺ olmalıdır.']]);
            }
            if ($orderItemId === null) {
                throw new \App\Exceptions\ValidationException(['refund' => ['Kısmi iade için bir sipariş kalemi seçilmelidir.']]);
            }
            $transactionId = self::transactionIdForItem($payment, $orderItemId);
            if ($transactionId === null) {
                throw new \App\Exceptions\ValidationException(['refund' => ['Seçilen kalem bu ödemeyle eşleştirilemedi.']]);
            }
        }

        // Sipariş zaten refund_pending ise tekrar geçiş yapılmaz (birden çok talep desteklenir)
        if ($status !== OrderStatus::REFUND_PENDING) {
            $this->orderService->transition((int) $order['id'], OrderStatus::REFUND_PENDING, 'customer', $customerId, $ip, [
                'reason' => 'refund_requested',
            ]);
        }

        $refundId = $this->refunds->insert([
            'order_id'    => (int) $order['id'],
            'payment_id'  => (int) $payment['id'],
            'type'        => 'refund',
            'scope'       => $isFull ? 'full' : 'partial',
            'status'      => 'pending',
            'amount'      => $isFull ? $remaining : round((float) $amount, 2),
            'currency'    => (string) $order['currency'],
            'payment_transaction_id' => $transactionId,
            'reason'      => mb_substr(trim($reason) !== '' ? $reason : 'Müşteri talebi', 0, 255, 'UTF-8'),
            'restore_status' => $status === OrderStatus::REFUND_PENDING ? OrderStatus::PAID : $status,
            'requested_by'   => 'customer',
            'requested_by_id' => $customerId,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        AuditLogger::log('refund.requested', null, $ip, $userAgent, 'order', (int) $order['id'], [
            'refund_id' => $refundId,
            'scope'     => $isFull ? 'full' : 'partial',
            'amount'    => $isFull ? $remaining : (float) $amount,
        ], 'customer');

        // AŞAMA 5: iade talebi bildirimi (tekrarsız kuyruk)
        \App\Services\Notification\NotificationService::refundStatusChanged($order, 'requested');

        return $refundId;
    }

    /**
     * ADMIN — iade talebini onaylar ve iyzico'ya gönderir.
     * Tam iade: tüm kırılımlar (itemTransactions) sırayla iade edilir.
     * Kısmi iade: yalnız ilgili kırılımın kalan tutarı iade edilir.
     */
    public function approve(int $refundId, int $adminId, string $ip, string $userAgent): void
    {
        $refund = $this->refunds->lockById($refundId);
        if ($refund === null) {
            throw new \App\Exceptions\ValidationException(['refund' => ['İade kaydı bulunamadı.']]);
        }
        if ((string) $refund['status'] !== 'pending') {
            throw new \App\Exceptions\ValidationException(['refund' => ['Yalnızca bekleyen talepler onaylanabilir.']]);
        }

        $orderId  = (int) $refund['order_id'];
        $payment  = $this->payments->find((int) $refund['payment_id']);
        $order    = $this->orders->find($orderId);
        if ($payment === null || $order === null) {
            throw new \App\Exceptions\ValidationException(['refund' => ['Sipariş/ödeme kaydı bulunamadı.']]);
        }

        $conversationId = (string) ($payment['conversation_id'] ?? (string) $order['order_number']);
        $currency       = (string) $refund['currency'];

        try {
            if ((string) $refund['type'] === 'cancel') {
                $response = $this->client->request((string) config('payment.endpoints.cancel'), [
                    'locale'         => (string) config('payment.locale', 'tr'),
                    'conversationId' => $conversationId,
                    'paymentId'      => (string) $payment['provider_payment_id'],
                    'ip'             => $ip !== '' ? $ip : '127.0.0.1',
                    'reason'         => 'BUYER_REQUEST',
                    'description'    => mb_substr((string) ($refund['reason'] ?? 'Sipariş iptali'), 0, 200, 'UTF-8'),
                ]);
                if (($response['status'] ?? '') !== 'success') {
                    throw new IyzipayException((string) ($response['errorMessage'] ?? 'iyzico iptali başarısız.'));
                }
                $reference = (string) ($response['cancelHostReference'] ?? '');
                $this->completeRefund($refund, $reference, $adminId, null);
                $this->payments->update((int) $payment['id'], [
                    'status'     => 'cancelled',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $this->orderService->transition($orderId, OrderStatus::CANCELLED, 'admin', $adminId, $ip, [
                    'refund_id' => $refundId,
                    'reason'    => 'payment_cancelled',
                ]);
                AuditLogger::log('payment.cancelled', $adminId, $ip, $userAgent, 'order', $orderId, [
                    'reference' => $reference,
                ], 'admin');
                return;
            }

            // ---- İade (kırılım bazlı) ----
            $trxs     = self::paymentItems($payment);
            $isFull   = ((string) $refund['scope'] === 'full');
            $targets  = [];
            if ($isFull) {
                // her kırılımın kalanı (önceki kısmi iadelerden artan)
                foreach ($trxs as $trx) {
                    $already = self::refundedForItem($orderId, (string) $trx['paymentTransactionId']);
                    $itemRemaining = round((float) $trx['paidPrice'] - $already, 2);
                    if ($itemRemaining > 0) {
                        $targets[] = ['trx' => (string) $trx['paymentTransactionId'], 'price' => $itemRemaining];
                    }
                }
            } else {
                $itemTrx  = (string) ($refund['payment_transaction_id'] ?? '');
                if ($itemTrx === '') {
                    throw new \App\Exceptions\ValidationException(['refund' => ['Kısmi iade için kırılım seçilmelidir.']]);
                }
                $already  = self::refundedForItem($orderId, $itemTrx);
                $itemPaid = self::itemPaidPrice($trxs, $itemTrx);
                $remainingItem = round($itemPaid - $already, 2);
                if ((float) $refund['amount'] > $remainingItem + 0.001) {
                    throw new \App\Exceptions\ValidationException(['refund' => ['Kırılım tutarını aşıyorsunuz.']]);
                }
                $targets[] = ['trx' => $itemTrx, 'price' => (float) $refund['amount']];
            }

            $references = [];
            foreach ($targets as $target) {
                $response = $this->client->request((string) config('payment.endpoints.refund'), [
                    'locale'         => (string) config('payment.locale', 'tr'),
                    'conversationId' => $conversationId,
                    'paymentTransactionId' => $target['trx'],
                    'price'          => number_format((float) $target['price'], 2, '.', ''),
                    'currency'       => $currency,
                    'ip'             => $ip !== '' ? $ip : '127.0.0.1',
                    'reason'         => 'BUYER_REQUEST',
                    'description'    => mb_substr((string) ($refund['reason'] ?? 'İade'), 0, 200, 'UTF-8'),
                ]);
                if (($response['status'] ?? '') !== 'success') {
                    throw new IyzipayException((string) ($response['errorMessage'] ?? 'iyzico iadesi başarısız (' . $target['trx'] . ').'));
                }
                $references[] = [
                    'paymentTransactionId' => $target['trx'],
                    'amount'               => number_format((float) $target['price'], 2, '.', ''),
                    'refundHostReference'  => (string) ($response['refundHostReference'] ?? ''),
                ];
            }

            $this->completeRefund($refund, $references[0]['refundHostReference'] ?? '', $adminId, $references);

            // Sipariş durumu
            $totalRefunded = $this->refunds->refundedTotal($orderId);
            $grandTotal    = (float) $order['grand_total'];
            if ($isFull || bccomp((string) $totalRefunded, (string) $grandTotal, 2) >= 0) {
                $this->payments->update((int) $payment['id'], ['status' => 'refunded', 'updated_at' => date('Y-m-d H:i:s')]);
                $this->orderService->transition($orderId, OrderStatus::REFUNDED, 'admin', $adminId, $ip, ['refund_id' => $refundId]);
            } else {
                $this->payments->update((int) $payment['id'], ['status' => 'partially_refunded', 'updated_at' => date('Y-m-d H:i:s')]);
                $restore = (string) ($refund['restore_status'] ?? OrderStatus::PAID);
                $current = (string) $order['status'];
                if ($current === OrderStatus::REFUND_PENDING && OrderStatus::can($current, $restore)) {
                    $this->orderService->transition($orderId, $restore, 'admin', $adminId, $ip, ['refund_id' => $refundId, 'reason' => 'partial_refund_restore']);
                }
            }

            AuditLogger::log('refund.completed', $adminId, $ip, $userAgent, 'order', $orderId, [
                'refund_id'  => $refundId,
                'references' => $references,
            ], 'admin');

            // AŞAMA 5: iade tamamlandı bildirimi
            \App\Services\Notification\NotificationService::refundStatusChanged($order, 'completed');
        } catch (IyzipayException $e) {
            $this->refunds->update((int) $refund['id'], [
                'status'        => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500, 'UTF-8'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            AuditLogger::log('refund.api_failed', $adminId, $ip, $userAgent, 'order', $orderId, [
                'refund_id' => $refundId,
                'reason'    => $e->getMessage(),
            ], 'admin');

            // AŞAMA 5: iade başarısız bildirimi
            \App\Services\Notification\NotificationService::refundStatusChanged($order, 'failed', $e->getMessage());
            throw new \App\Exceptions\ValidationException(['refund' => ['iyzico işlemi başarısız: ' . $e->getMessage()]]);
        }
    }

    /** ADMIN — iade talebini reddeder; sipariş önceki durumuna döner. */
    public function reject(int $refundId, int $adminId, string $reason, string $ip): void
    {
        $refund = $this->refunds->find($refundId);
        if ($refund === null) {
            throw new \App\Exceptions\ValidationException(['refund' => ['İade kaydı bulunamadı.']]);
        }
        if ((string) $refund['status'] !== 'pending') {
            throw new \App\Exceptions\ValidationException(['refund' => ['Yalnızca bekleyen talepler reddedilebilir.']]);
        }

        $this->refunds->update($refundId, [
            'status'     => 'rejected',
            'note'       => mb_substr($reason, 0, 500, 'UTF-8'),
            'handled_by' => $adminId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $orderId  = (int) $refund['order_id'];
        $order    = $this->orders->find($orderId);
        $pendingLeft = $this->orderHasPendingRefunds($orderId);
        if ($order !== null && (string) $order['status'] === OrderStatus::REFUND_PENDING && !$pendingLeft) {
            $restore = (string) ($refund['restore_status'] ?? OrderStatus::PAID);
            if (OrderStatus::can(OrderStatus::REFUND_PENDING, $restore)) {
                $this->orderService->transition($orderId, $restore, 'admin', $adminId, $ip, [
                    'refund_id' => $refundId,
                    'reason'    => 'refund_rejected_restore',
                ]);
            }
        }

        AuditLogger::log('refund.rejected', $adminId, $ip, '', 'order', $orderId, [
            'refund_id' => $refundId,
            'reason'    => $reason,
        ], 'admin');

        // AŞAMA 5: iade reddi bildirimi
        if ($order !== null) {
            \App\Services\Notification\NotificationService::refundStatusChanged($order, 'rejected', $reason);
        }
    }

    /**
     * ADMIN — siparişi iptal eder. Başarılı ödemesi varsa iyzico İPTAL işlemi
     * (payment/cancel) çalıştırılır; yoksa yalnızca durum geçişi yapılır.
     */
    public function cancelByAdmin(string $orderNumber, int $adminId, string $reason, string $ip, string $userAgent): void
    {
        $order = $this->orders->findAnyByNumber($orderNumber);
        if ($order === null) {
            throw new \App\Exceptions\ValidationException(['order' => ['Sipariş bulunamadı.']]);
        }
        $status = (string) $order['status'];
        if (!in_array($status, [OrderStatus::PENDING_PAYMENT, OrderStatus::PAYMENT_FAILED, ...OrderStatus::cancellableAfterPayment()], true)) {
            throw new \App\Exceptions\ValidationException(['order' => [
                'Bu durumdaki sipariş iptal edilemez (' . OrderStatus::label($status) . ').',
            ]]);
        }

        $payment = $this->payments->successForOrder((int) $order['id']);
        if ($payment !== null) {
            // refunds kaydı (cancel) + iyzico iptali approve() üzerinden
            $refundId = $this->refunds->insert([
                'order_id'    => (int) $order['id'],
                'payment_id'  => (int) $payment['id'],
                'type'        => 'cancel',
                'scope'       => 'full',
                'status'      => 'pending',
                'amount'      => (float) $order['grand_total'],
                'currency'    => (string) $order['currency'],
                'reason'      => mb_substr($reason !== '' ? $reason : 'Yönetici iptali', 0, 255, 'UTF-8'),
                'restore_status' => null,
                'requested_by' => 'admin',
                'requested_by_id' => $adminId,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
            $this->approve($refundId, $adminId, $ip, $userAgent);
            return;
        }

        // Ödeme yok (pending_payment / payment_failed) → doğrudan geçiş
        $this->orderService->transition((int) $order['id'], OrderStatus::CANCELLED, 'admin', $adminId, $ip, [
            'reason' => mb_substr($reason, 0, 200, 'UTF-8'),
        ]);
    }

    /** İade tamamlandı kaydı (referanslar context JSON'a yazılır). */
    private function completeRefund(array $refund, string $reference, int $adminId, ?array $contextList): void
    {
        $this->refunds->update((int) $refund['id'], [
            'status'          => 'completed',
            'refund_reference' => mb_substr($reference, 0, 100, 'UTF-8') ?: null,
            'context'         => $contextList !== null ? json_encode($contextList, JSON_UNESCAPED_UNICODE) : null,
            'handled_by'      => $adminId,
            'completed_at'    => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    private function orderHasPendingRefunds(int $orderId): bool
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM refunds WHERE order_id = ? AND status = "pending"');
        $stmt->execute([$orderId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * payments.raw_response içinden itemTransactions listesini çözer.
     * @return list<array<string,mixed>>
     */
    public static function paymentItems(array $payment): array
    {
        $raw = $payment['raw_response'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $items = $decoded['itemTransactions'] ?? [];
        return is_array($items) ? array_values($items) : [];
    }

    private static function itemPaidPrice(array $trxs, string $transactionId): float
    {
        foreach ($trxs as $trx) {
            if ((string) ($trx['paymentTransactionId'] ?? '') === $transactionId) {
                return (float) ($trx['paidPrice'] ?? 0);
            }
        }
        return 0.0;
    }

    /** Bir kırılım için daha önce tamamlanan iade toplamı. */
    private static function refundedForItem(int $orderId, string $transactionId): float
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(SUM(amount),0) FROM refunds
             WHERE order_id = ? AND status = "completed" AND payment_transaction_id = ?'
        );
        $stmt->execute([$orderId, $transactionId]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /** order_item.id → iyzico paymentTransactionId eşlemesi (itemId = order_item id). */
    public static function transactionIdForItem(array $payment, int $orderItemId): ?string
    {
        foreach (self::paymentItems($payment) as $trx) {
            if ((string) ($trx['itemId'] ?? '') === (string) $orderItemId) {
                return (string) ($trx['paymentTransactionId'] ?? '') ?: null;
            }
        }
        return null;
    }
}
