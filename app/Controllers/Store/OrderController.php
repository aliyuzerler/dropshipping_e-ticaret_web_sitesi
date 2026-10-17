<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\OrderRepository;
use App\Services\Auth\CustomerAuthService;

/**
 * Siparişlerim: sayfalı liste + detay (IDOR korumalı — tüm okumalar
 * customer_id parametreli OrderRepository metotları üzerinden).
 *
 * Durum eşlemesi (view'lar ile ortak): pending=Onay Bekliyor/warning,
 * processing=Hazırlanıyor/info, shipped=Kargoda/primary,
 * delivered=Teslim Edildi/success, cancelled=İptal Edildi/danger.
 *
 * Tam yol: app/Controllers/Store/OrderController.php
 */
final class OrderController
{
    private const PER_PAGE = 10;

    private OrderRepository $orders;

    private CustomerAuthService $auth;

    public function __construct(
        ?OrderRepository $orders = null,
        ?CustomerAuthService $auth = null
    ) {
        $this->orders = $orders ?? new OrderRepository();
        $this->auth   = $auth ?? new CustomerAuthService();
    }

    public function index(Request $request): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();

        $total    = $this->orders->countForCustomer($customerId);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));

        $page = (int) ($request->input('page') ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        if ($page > $lastPage) {
            $page = $lastPage;
        }

        return View::render('store/account/orders', [
            'title'    => 'Siparişlerim',
            'orders'   => $this->orders->forCustomer($customerId, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page'     => $page,
            'lastPage' => $lastPage,
            'total'    => $total,
        ], 'layouts/store');
    }

    public function show(Request $request, string $number): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();

        $order = $this->orders->findByNumberForCustomer($number, $customerId);
        if ($order === null) {
            abort(404, 'Sipariş bulunamadı.');
        }

        return View::render('store/account/order_detail', [
            'title'    => 'Sipariş ' . (string) $order['order_number'],
            'order'    => $order,
            'items'    => $this->orders->itemsForOrder((int) $order['id']),
            'shipping' => $this->decodeSnapshot($order['shipping_snapshot'] ?? null),
            'billing'  => $this->decodeSnapshot($order['billing_snapshot'] ?? null),
            'payments' => (new \App\Repositories\PaymentRepository())->forOrder((int) $order['id']),
            'refunds'  => (new \App\Repositories\RefundRepository())->forOrder((int) $order['id']),
            // AŞAMA 5: kargo takibi — tedarikçi siparişleri + gönderiler
            'shipments' => (new \App\Repositories\ShipmentRepository())->forOrder((int) $order['id']),
        ], 'layouts/store');
    }

    /**
     * JSON snapshot kolonunu güvenle diziye çevirir (bozuk/boş → []).
     *
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
