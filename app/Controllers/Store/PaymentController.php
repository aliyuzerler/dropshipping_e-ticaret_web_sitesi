<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\RefundRepository;
use App\Services\Auth\CustomerAuthService;
use App\Services\Order\OrderStatus;
use App\Services\Payment\CheckoutFormService;
use App\Services\Payment\IyzipayException;
use App\Services\Payment\PaymentService;
use App\Services\Order\RefundService;
use Throwable;

/**
 * AŞAMA 4 — iyzico Checkout Form akışı (müşteri tarafı):
 *
 *  GET  /odeme/onay/{number}   → Checkout Form başlatır + formu gösterir
 *  POST /odeme/callback        → iyzico token POST'u (CSRF YOK — token doğrulamalı);
 *                                sonuç SUNUCUDA sorgulanır, sonra sonuç sayfasına 303
 *  GET  /odeme/sonuc/{number}  → sonuç ekranı (initiated ise kurtarma sorgusu yapar)
 *  POST /odeme/simulasyon      → yalnız mock modda: başarı/başarısızlık simülasyonu
 *  POST /hesabim/siparisler/{number}/iade-talebi → müşteri iade talebi
 *
 * Güvenlik: tüm sipariş okumaları müşteri sahipliğiyle (IDOR koruması); callback
 * yalnız payments.token ile çalışır; başarı yalnız sunucu sorgusuyla işlenir.
 *
 * Tam yol: app/Controllers/Store/PaymentController.php
 */
final class PaymentController
{
    private OrderRepository $orders;

    private PaymentRepository $payments;

    private RefundRepository $refunds;

    private CustomerAuthService $auth;

    private CheckoutFormService $checkoutForm;

    private PaymentService $paymentService;

    private RefundService $refundService;

    public function __construct(
        ?OrderRepository $orders = null,
        ?PaymentRepository $payments = null,
        ?RefundRepository $refunds = null,
        ?CustomerAuthService $auth = null,
        ?CheckoutFormService $checkoutForm = null,
        ?PaymentService $paymentService = null,
        ?RefundService $refundService = null
    ) {
        $this->orders         = $orders ?? new OrderRepository();
        $this->payments       = $payments ?? new PaymentRepository();
        $this->refunds        = $refunds ?? new RefundRepository();
        $this->auth           = $auth ?? new CustomerAuthService();
        $this->checkoutForm   = $checkoutForm ?? new CheckoutFormService();
        $this->paymentService = $paymentService ?? new PaymentService();
        $this->refundService  = $refundService ?? new RefundService();
    }

    /** Checkout Form sayfası. */
    public function showPay(Request $request, string $number): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();
        $order = $this->orders->findByNumberForCustomer($number, $customerId);
        if ($order === null) {
            abort(404, 'Sipariş bulunamadı.');
        }

        // Ödenmiş ise doğrudan siparişe
        if ((string) $order['status'] === OrderStatus::PAID) {
            return redirect('/hesabim/siparisler/' . rawurlencode($number));
        }
        if (!in_array((string) $order['status'], OrderStatus::payable(), true)) {
            flash('error', 'Bu sipariş için ödeme başlatılamaz (durum: ' . OrderStatus::label((string) $order['status']) . ').');
            return redirect('/hesabim/siparisler/' . rawurlencode($number));
        }

        $items = $this->orders->itemsForOrder((int) $order['id']);
        $initError = null;
        $content = null;
        $pageUrl = null;
        try {
            $init = $this->checkoutForm->initializeForOrder($order, $items);
            $content = $init['content'];
            $pageUrl = $init['page_url'];
            $order   = $this->orders->findAnyByNumber($number) ?? $order;
        } catch (IyzipayException $e) {
            $initError = $e->getMessage();
        } catch (Throwable $e) {
            Logger::error('app', 'payment.init_error', ['order' => $number, 'reason' => $e->getMessage()]);
            $initError = 'Ödeme formu başlatılamadı. Lütfen tekrar deneyin.';
        }

        $payment = $this->payments->latestForOrder((int) $order['id']);
        $failedPayments = array_values(array_filter(
            $this->payments->forOrder((int) $order['id']),
            static fn ($p) => in_array((string) $p['status'], ['failed', 'initiation_failed'], true)
        ));

        return View::render('store/checkout/pay', [
            'title'     => 'Ödeme — ' . (string) $order['order_number'],
            'order'     => $order,
            'items'     => $items,
            'payment'   => $payment,
            'content'   => $content,
            'pageUrl'   => $pageUrl,
            'initError' => $initError,
            'isMock'    => $this->paymentService->isMock(),
            'failedPayments' => $failedPayments,
        ], 'layouts/store');
    }

    /**
     * iyzico callback: token POST'u. CSRF YOK — kimlik doğrulama token + sunucu
     * sorgusuyla yapılır (iyzico tarayıcı yönlendirmesi yapar). Sonuç işlendikten
     * sonra müşteri sonuç sayfasına yönlendirilir.
     */
    public function callback(Request $request): Response
    {
        $token = trim((string) $request->input('token', ''));
        if ($token === '') {
            Logger::warning('security', 'payment.callback_empty', ['ip' => $request->ip()]);
            return redirect('/sepet', 303);
        }

        try {
            $result = $this->paymentService->processToken($token, $request->ip(), 'callback');
            $orderNumber = (string) ($result['order']['order_number'] ?? '');
            if ($orderNumber === '') {
                $p = $this->payments->findByToken($token);
                $o = $p !== null ? $this->orders->find((int) $p['order_id']) : null;
                $orderNumber = (string) ($o['order_number'] ?? '');
            }
            if ($result['outcome'] === 'success' || $result['outcome'] === 'replay') {
                flash('success', 'Ödemeniz başarıyla alındı. Teşekkür ederiz!');
            } elseif ($result['outcome'] === 'failed') {
                flash('error', 'Ödeme başarısız oldu. Ödeme adımından yeniden deneyebilirsiniz.');
            } else {
                flash('info', 'Ödemeniz işleniyor; sonuç kısa süre içinde sipariş sayfanıza yansır.');
            }
            if ($orderNumber !== '') {
                return redirect('/odeme/sonuc/' . rawurlencode($orderNumber), 303);
            }
            return redirect('/hesabim/siparisler', 303);
        } catch (IyzipayException $e) {
            // Bilinmeyen/sahte token: işlem yok — güvenlik logu serviste yazıldı
            flash('error', 'Ödeme sonucu doğrulanamadı.');
            return redirect('/sepet', 303);
        }
    }

    /** Sonuç sayfası: initiated kalmışsa kurtarma sorgusu (callback gelmediyse). */
    public function result(Request $request, string $number): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();
        $order = $this->orders->findByNumberForCustomer($number, $customerId);
        if ($order === null) {
            abort(404, 'Sipariş bulunamadı.');
        }

        $payment = $this->payments->latestForOrder((int) $order['id']);
        $recovered = false;
        if ($payment !== null && (string) $payment['status'] === 'initiated' && (string) ($payment['token'] ?? '') !== '') {
            try {
                $result = $this->paymentService->processToken((string) $payment['token'], $request->ip(), 'result-recovery');
                $recovered = $result['changed'];
                $order = $this->orders->findAnyByNumber($number) ?? $order;
                $payment = $this->payments->latestForOrder((int) $order['id']);
            } catch (IyzipayException) {
                // sorgu başarısız — initiated görünümde kalır
            }
        }

        $refunds = $this->refunds->forOrder((int) $order['id']);

        return View::render('store/checkout/result', [
            'title'     => 'Ödeme Sonucu — ' . (string) $order['order_number'],
            'order'     => $order,
            'payment'   => $payment,
            'refunds'   => $refunds,
            'recovered' => $recovered,
        ], 'layouts/store');
    }

    /**
     * Mock simülasyonu: yalnız IYZICO_MODE=mock. iyzico davranışını taklit eder —
     * senaryo seçilir, token SUNUCUDA sorgulanır (callback ile aynı kod yolu).
     */
    public function mockPay(Request $request): Response
    {
        if (!$this->paymentService->isMock()) {
            abort(404, 'Simülasyon yalnız mock modunda kullanılabilir.');
        }

        $token    = trim((string) $request->input('token', ''));
        $outcome  = (string) $request->input('outcome', 'success');
        $customerId = (int) $this->auth->currentCustomerId();

        $payment = $token !== '' ? $this->payments->findByToken($token) : null;
        if ($payment === null) {
            abort(404, 'Ödeme kaydı bulunamadı.');
        }
        $order = $this->orders->find((int) $payment['order_id']);
        if ($order === null || (int) $order['customer_id'] !== $customerId) {
            abort(404, 'Sipariş bulunamadı.');
        }

        try {
            $this->paymentService->setMockOutcome($token, $outcome === 'failure' ? 'failure' : 'success');
            $result = $this->paymentService->processToken($token, $request->ip(), 'mock');
            if ($result['outcome'] === 'success' || $result['outcome'] === 'replay') {
                flash('success', 'Simülasyon: ödeme başarılı olarak işlendi.');
            } elseif ($result['outcome'] === 'failed') {
                flash('error', 'Simülasyon: ödeme başarısız olarak işlendi.');
            } else {
                flash('info', 'Simülasyon: ödeme beklemede.');
            }
            return redirect('/odeme/sonuc/' . rawurlencode((string) $order['order_number']));
        } catch (IyzipayException $e) {
            flash('error', $e->getMessage());
            return redirect('/odeme/onay/' . rawurlencode((string) $order['order_number']));
        }
    }

    /** Müşteri iade talebi (tam veya kısmi). */
    public function requestRefund(Request $request, string $number): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();
        $scope   = (string) $request->input('scope', 'full');
        $amount  = $scope === 'partial' ? (float) str_replace(',', '.', (string) $request->input('amount', '0')) : null;
        $itemId  = $scope === 'partial' ? (int) ($request->input('order_item_id', 0) ?: 0) : null;
        $reason  = trim((string) $request->input('reason', ''));

        try {
            $this->refundService->requestByCustomer($number, $customerId, $amount, $reason, $request->ip(), $request->userAgent(), $itemId);
            flash('success', 'İade talebiniz alındı. En kısa sürede değerlendirilecek.');
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $messages[] = $msg;
                }
            }
            flash('error', implode(' ', $messages));
        }

        return redirect('/hesabim/siparisler/' . rawurlencode($number));
    }
}
