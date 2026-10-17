<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Repositories\AddressRepository;
use App\Services\Auth\CustomerAuthService;
use App\Services\Store\CartService;
use App\Services\Store\CheckoutService;

/**
 * Checkout — sipariş oluşturma. AŞAMA 4: sipariş 'pending_payment' durumuyla
 * kaydedilir ve müşteri iyzico Checkout Form sayfasına yönlendirilir.
 *
 * Tam yol: app/Controllers/Store/CheckoutController.php
 */
final class CheckoutController
{
    private CartService $cart;

    private CheckoutService $checkout;

    private AddressRepository $addresses;

    private CustomerAuthService $auth;

    public function __construct(
        ?CartService $cart = null,
        ?CheckoutService $checkout = null,
        ?AddressRepository $addresses = null,
        ?CustomerAuthService $auth = null
    ) {
        $this->cart = $cart ?? new CartService();
        $this->checkout = $checkout ?? new CheckoutService();
        $this->addresses = $addresses ?? new AddressRepository();
        $this->auth = $auth ?? new CustomerAuthService();
    }

    public function show(Request $request): Response
    {
        $summary = $this->cart->summary();

        if ($summary['count'] === 0) {
            flash('error', 'Ödeme yapmak için sepetinizde ürün olmalı.');
            return redirect('/sepet');
        }

        $customerId = (int) $this->auth->currentCustomerId();
        $addresses = $this->addresses->allForCustomer($customerId);
        $defaultAddress = $this->addresses->defaultForCustomer($customerId);

        return View::render('store/checkout/show', [
            'title'          => 'Ödeme — Siparişi Tamamla',
            'summary'        => $summary,
            'addresses'      => $addresses,
            'defaultAddress' => $defaultAddress,
            'customer'       => $this->auth->currentCustomer(),
        ], 'layouts/store');
    }

    public function place(Request $request): Response
    {
        try {
            $result = $this->checkout->placeOrder($request->post(), $request->ip(), $request->userAgent());
            flash('success', 'Siparişiniz oluşturuldu! Ödeme adımına yönlendiriliyorsunuz.');
            // AŞAMA 4: sipariş oluşturuldu → iyzico Checkout Form sayfası
            return redirect('/odeme/onay/' . rawurlencode($result['number']));
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $fieldErrors) {
                foreach ($fieldErrors as $msg) {
                    $messages[] = $msg;
                }
            }
            flash('error', implode(' ', $messages));
            Session::flash('old', $request->post());
            return redirect('/odeme');
        }
    }
}
