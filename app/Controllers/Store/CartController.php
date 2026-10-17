<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Services\Store\CartService;

/**
 * Sepet ekranı — misafir + üye. Tüm tutarlar CartService ile sunucu
 * tarafında hesaplanır; formdan gelen tutarlara güvenilmez.
 *
 * Tam yol: app/Controllers/Store/CartController.php
 */
final class CartController
{
    private CartService $cart;

    public function __construct(?CartService $cart = null)
    {
        $this->cart = $cart ?? new CartService();
    }

    public function show(Request $request): Response
    {
        $summary = $this->cart->summary();

        return View::render('store/cart/show', [
            'title'          => 'Sepetim',
            'summary'        => $summary,
            'validationErrors' => $summary['has_unavailable'] ? [] : [],
        ], 'layouts/store');
    }

    public function add(Request $request): Response
    {
        try {
            $productId = (int) $request->input('product_id', 0);
            $variantRaw = $request->input('variant_id');
            $variantId = ($variantRaw === null || $variantRaw === '' || (int) $variantRaw === 0) ? null : (int) $variantRaw;
            $quantity = max(1, (int) $request->input('quantity', 1));

            $this->cart->add($productId, $variantId, $quantity);
            flash('success', 'Ürün sepete eklendi.');
        } catch (ValidationException $e) {
            flash('error', $e->firstError());
        }

        return redirect('/sepet');
    }

    public function update(Request $request): Response
    {
        try {
            $itemId = (int) $request->input('item_id', 0);
            $quantity = (int) $request->input('quantity', 1);
            $this->cart->updateQuantity($itemId, $quantity);
            flash('success', 'Sepet güncellendi.');
        } catch (ValidationException $e) {
            flash('error', $e->firstError());
        }
        return redirect('/sepet');
    }

    public function remove(Request $request): Response
    {
        $itemId = (int) $request->input('item_id', 0);
        $this->cart->remove($itemId);
        flash('info', 'Ürün sepetten kaldırıldı.');
        return redirect('/sepet');
    }

    public function clear(Request $request): Response
    {
        $this->cart->clear();
        flash('info', 'Sepet boşaltıldı.');
        return redirect('/sepet');
    }

    public function applyCoupon(Request $request): Response
    {
        try {
            $code = trim((string) $request->input('code', ''));
            $coupon = $this->cart->applyCoupon($code);
            flash('success', 'Kupon uygulandı: ' . $coupon['code']);
        } catch (ValidationException $e) {
            flash('error', $e->firstError());
        }
        return redirect('/sepet');
    }

    public function removeCoupon(Request $request): Response
    {
        $this->cart->removeCoupon();
        flash('info', 'Kupon kaldırıldı.');
        return redirect('/sepet');
    }
}
