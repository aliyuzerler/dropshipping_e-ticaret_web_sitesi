<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
use App\Repositories\SupplierOrderRepository;
use App\Repositories\WebhookEventRepository;
use App\Services\Auth\AdminAuthService;
use App\Services\Order\OrderService;
use App\Services\Order\OrderStatus;
use App\Services\Order\RefundService;
use Throwable;

/**
 * AŞAMA 4 — Admin sipariş yönetimi:
 *  - liste (durum/arama filtreli) + detay (ödeme, iade, tedarikçi siparişi, DENETİM İZİ)
 *  - durum geçişi (yalnız durum makinesinin izin verdiği hedefler)
 *  - iptal (iyzico payment/cancel) + iade onay/red
 *
 * Tüm işlemler audit_logs'a yazılır (yönetici işlem logları).
 * Tam yol: app/Controllers/Admin/OrderAdminController.php
 */
final class OrderAdminController
{
    private const PER_PAGE = 15;

    private OrderRepository $orders;

    private PaymentRepository $payments;

    private RefundRepository $refunds;

    private SupplierOrderRepository $supplierOrders;

    private AuditLogRepository $audit;

    private WebhookEventRepository $webhooks;

    private OrderService $orderService;

    private RefundService $refundService;

    private AdminAuthService $auth;

    public function __construct(
        ?OrderRepository $orders = null,
        ?PaymentRepository $payments = null,
        ?RefundRepository $refunds = null,
        ?SupplierOrderRepository $supplierOrders = null,
        ?AuditLogRepository $audit = null,
        ?WebhookEventRepository $webhooks = null,
        ?OrderService $orderService = null,
        ?RefundService $refundService = null,
        ?AdminAuthService $auth = null
    ) {
        $this->orders         = $orders ?? new OrderRepository();
        $this->payments       = $payments ?? new PaymentRepository();
        $this->refunds        = $refunds ?? new RefundRepository();
        $this->supplierOrders = $supplierOrders ?? new SupplierOrderRepository();
        $this->audit          = $audit ?? new AuditLogRepository();
        $this->webhooks       = $webhooks ?? new WebhookEventRepository();
        $this->orderService   = $orderService ?? new OrderService();
        $this->refundService  = $refundService ?? new RefundService();
        $this->auth           = $auth ?? new AdminAuthService();
    }

    public function index(Request $request): Response
    {
        $status = trim((string) ($request->input('status') ?? ''));
        $q      = trim((string) ($request->input('q') ?? ''));
        $page   = max(1, (int) ($request->input('page') ?? 1));

        $total    = $this->orders->countAdmin($status !== '' ? $status : null, $q);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page     = min($page, $lastPage);

        return View::render('admin/orders/index', [
            'title'      => 'Siparişler',
            'orders'     => $this->orders->adminList($status !== '' ? $status : null, $q, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'statusCounts' => $this->orders->statusCounts(),
            'statuses'   => $this->statusList(),
            'filterStatus' => $status,
            'q'          => $q,
            'page'       => $page,
            'lastPage'   => $lastPage,
            'total'      => $total,
            'pendingRefunds' => count($this->refunds->pending()),
        ], 'layouts/base');
    }

    public function show(Request $request, string $number): Response
    {
        $order = $this->orders->findAnyByNumber($number);
        if ($order === null) {
            abort(404, 'Sipariş bulunamadı.');
        }

        $orderId = (int) $order['id'];
        $status  = (string) $order['status'];

        return View::render('admin/orders/show', [
            'title'          => 'Sipariş ' . (string) $order['order_number'],
            'order'          => $order,
            'items'          => $this->orders->itemsForOrder($orderId),
            'payments'       => $this->payments->forOrder($orderId),
            'refunds'        => $this->refunds->forOrder($orderId),
            'supplierOrders' => $this->supplierOrders->forOrder($orderId),
            'timeline'       => $this->audit->forEntity('order', $orderId),
            'allowedTargets' => OrderStatus::allowedTargets($status),
            'statuses'       => $this->statusList(),
            'shipping'       => $this->decodeSnapshot($order['shipping_snapshot'] ?? null),
            'billing'        => $this->decodeSnapshot($order['billing_snapshot'] ?? null),
            'cancellable'    => in_array($status, [OrderStatus::PENDING_PAYMENT, OrderStatus::PAYMENT_FAILED, ...OrderStatus::cancellableAfterPayment()], true),
            'refundable'     => in_array($status, OrderStatus::refundRequestable(), true),
        ], 'layouts/base');
    }

    /** Durum geçişi (yalnız izinli hedefler; geçersiz geçiş reddedilir). */
    public function updateStatus(Request $request, string $id): Response
    {
        $to = (string) $request->input('status', '');
        try {
            $adminId  = (int) $this->auth->currentAdminId();
            $this->orderService->transition((int) $id, $to, 'admin', $adminId, $request->ip(), [
                'via' => 'admin_panel',
            ]);
            flash('success', 'Sipariş durumu güncellendi: ' . OrderStatus::label($to));
        } catch (ValidationException $e) {
            $msgs = [];
            foreach ($e->errors() as $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $msgs[] = $msg;
                }
            }
            flash('error', implode(' ', $msgs));
        } catch (Throwable $e) {
            Logger::error('app', 'admin.order_status_failed', ['order_id' => $id, 'reason' => $e->getMessage()]);
            flash('error', 'Durum güncellenemedi.');
        }
        return redirect('/admin/orders/' . rawurlencode((string) ($request->input('order_number', ''))));
    }

    /** Sipariş iptali (ödemeli ise iyzico payment/cancel). */
    public function cancelOrder(Request $request, string $id): Response
    {
        $number = (string) ($request->input('order_number', ''));
        try {
            $adminId = (int) $this->auth->currentAdminId();
            $this->refundService->cancelByAdmin($number, $adminId, (string) $request->input('reason', ''), $request->ip(), $request->userAgent());
            flash('success', 'Sipariş iptal edildi.');
        } catch (ValidationException $e) {
            $msgs = [];
            foreach ($e->errors() as $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $msgs[] = $msg;
                }
            }
            flash('error', implode(' ', $msgs));
        }
        return redirect('/admin/orders/' . rawurlencode($number));
    }

    /** İade talebi onayı (iyzico refund/cancel çağrısı). */
    public function approveRefund(Request $request, string $id): Response
    {
        try {
            $adminId = (int) $this->auth->currentAdminId();
            $refund  = $this->refunds->find((int) $id);
            $this->refundService->approve((int) $id, $adminId, $request->ip(), $request->userAgent());
            flash('success', 'İade işlemi tamamlandı.');
            if ($refund !== null) {
                $order = (new OrderRepository())->find((int) $refund['order_id']);
                if ($order !== null) {
                    return redirect('/admin/orders/' . rawurlencode((string) $order['order_number']));
                }
            }
        } catch (ValidationException $e) {
            $msgs = [];
            foreach ($e->errors() as $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $msgs[] = $msg;
                }
            }
            flash('error', implode(' ', $msgs));
        }
        return redirect('/admin/orders');
    }

    /** İade talebi reddi. */
    public function rejectRefund(Request $request, string $id): Response
    {
        try {
            $adminId = (int) $this->auth->currentAdminId();
            $refund  = $this->refunds->find((int) $id);
            $this->refundService->reject((int) $id, $adminId, (string) $request->input('reason', ''), $request->ip());
            flash('success', 'İade talebi reddedildi.');
            if ($refund !== null) {
                $order = (new OrderRepository())->find((int) $refund['order_id']);
                if ($order !== null) {
                    return redirect('/admin/orders/' . rawurlencode((string) $order['order_number']));
                }
            }
        } catch (ValidationException $e) {
            $msgs = [];
            foreach ($e->errors() as $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $msgs[] = $msg;
                }
            }
            flash('error', implode(' ', $msgs));
        }
        return redirect('/admin/orders');
    }

    /**
     * @return array<string,string>
     */
    private function statusList(): array
    {
        $out = [];
        foreach (OrderStatus::LABELS as $status => $label) {
            $out[$status] = $label;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeSnapshot(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
