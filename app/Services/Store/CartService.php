<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Core\Session;
use App\Exceptions\ValidationException;
use App\Models\Product;
use App\Repositories\CartItemRepository;
use App\Repositories\CartRepository;
use App\Repositories\CouponRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;

/**
 * Sepet motoru — misafir (session token) + üye (customer_id) sepeti,
 * girişte birleştirme, stok koruması ve SUNUCU TARAFLI tutar hesabı.
 *
 * Güvenlik ilkesi: tarayıcıdan gelen fiyat/adet/indirim tutarına asla
 * güvenilmez; tüm kalemler her okumada DB'den taze fiyatla yeniden hesaplanır.
 *
 * Tam yol: app/Services/Store/CartService.php
 */
final class CartService
{
    private CartRepository $carts;

    private CartItemRepository $items;

    private ProductRepository $products;

    private ProductVariantRepository $variants;

    private CouponRepository $coupons;

    private CouponService $couponService;

    private StoreProductService $storeProducts;

    public function __construct(
        ?CartRepository $carts = null,
        ?CartItemRepository $items = null,
        ?ProductRepository $products = null,
        ?ProductVariantRepository $variants = null,
        ?CouponRepository $coupons = null,
        ?CouponService $couponService = null,
        ?StoreProductService $storeProducts = null
    ) {
        $this->carts = $carts ?? new CartRepository();
        $this->items = $items ?? new CartItemRepository();
        $this->products = $products ?? new ProductRepository();
        $this->variants = $variants ?? new ProductVariantRepository();
        $this->coupons = $coupons ?? new CouponRepository();
        $this->couponService = $couponService ?? new CouponService();
        $this->storeProducts = $storeProducts ?? new StoreProductService();
    }

    /* ---------------------------------------------------------------------
     * Sepet çözümleme
     * ------------------------------------------------------------------- */

    /** Oturuma bağlı sepet token'ı (misafir sepeti için). */
    private function ensureToken(): string
    {
        $token = Session::get('cart_token');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            Session::set('cart_token', $token);
        }
        return $token;
    }

    /**
     * Aktif sepeti döndürür; yoksa (ve istendiyse) oluşturur.
     * @return array<string,mixed>|null
     */
    public function resolve(bool $createIfMissing = true): ?array
    {
        $customerId = Session::get('customer_id');
        $isCustomer = is_int($customerId) && $customerId > 0;
        $token = $this->ensureToken();

        if ($isCustomer) {
            $cart = $this->carts->findActiveByCustomer($customerId);
            if ($cart === null) {
                // Misafir sepeti varsa devral (giriş sonrası ilk erişim)
                $guest = $this->carts->findActiveBySession($token);
                if ($guest !== null) {
                    $this->carts->attachCustomer((int) $guest['id'], $customerId);
                    /** @var array<string,mixed> $cart */
                    $cart = $this->carts->find((int) $guest['id']);
                } elseif ($createIfMissing) {
                    $cart = $this->carts->create($customerId, bin2hex(random_bytes(32)));
                }
            }
            return $cart;
        }

        $cart = $this->carts->findActiveBySession($token);
        if ($cart === null && $createIfMissing) {
            $cart = $this->carts->create(null, $token);
        }
        return $cart;
    }

    /* ---------------------------------------------------------------------
     * Sepet işlemleri
     * ------------------------------------------------------------------- */

    /** Ürün/varyant sepete ekler; stok ve satın alınabilirlik doğrulanır. */
    public function add(int $productId, ?int $variantId, int $quantity): void
    {
        if ($quantity < 1 || $quantity > 999) {
            throw new ValidationException(['quantity' => ['Adet 1-999 arasında olmalıdır.']]);
        }

        $product = $this->products->find($productId);
        if ($product === null || $product['status'] !== Product::STATUS_ACTIVE || $product['deleted_at'] !== null) {
            throw new ValidationException(['cart' => ['Bu ürün artık satışta değil.']]);
        }

        $stock = (int) $product['stock'];
        $variant = null;
        if ($variantId !== null) {
            $variant = $this->variants->findActive($variantId);
            if ($variant === null || (int) $variant['product_id'] !== $productId) {
                throw new ValidationException(['variant' => ['Geçersiz ürün seçeneği.']]);
            }
            $stock = (int) $variant['stock'];
        }

        if ($this->storeProducts->staleProductIds([$productId]) !== []) {
            throw new ValidationException(['cart' => ['Tedarikçi stok bilgisi güncel olmadığı için bu ürün şu anda satın alınamaz.']]);
        }

        if ($stock <= 0) {
            throw new ValidationException(['cart' => ['Bu ürün stokta yok.']]);
        }

        $cart = $this->resolve(true);
        if ($cart === null) {
            throw new ValidationException(['cart' => ['Sepet oluşturulamadı.']]);
        }

        $existing = $this->items->findByCartProductVariant((int) $cart['id'], $productId, $variantId);
        $newQuantity = $quantity + ($existing !== null ? (int) $existing['quantity'] : 0);

        if ($newQuantity > $stock) {
            throw new ValidationException(['quantity' => [
                'Stok yetersiz — en fazla ' . $stock . ' adet ekleyebilirsiniz.',
            ]]);
        }

        $unitPrice = $variant !== null
            ? ProductVariantRepository::effectivePrice($variant['price'] ?? null, $product['sale_price'] ?? null)
            : (float) ($product['sale_price'] ?? 0);

        if ($existing !== null) {
            $this->items->updateQuantity((int) $existing['id'], $newQuantity);
        } else {
            $this->items->insert([
                'cart_id'    => (int) $cart['id'],
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity'   => $newQuantity,
                'unit_price' => $unitPrice,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** Kalem adedini günceller (0 → kaldır). Stok üst sınırı zorlanır. */
    public function updateQuantity(int $itemId, int $quantity): void
    {
        $cart = $this->resolve(false);
        if ($cart === null) {
            throw new ValidationException(['cart' => ['Sepet bulunamadı.']]);
        }

        $row = $this->items->findForCart((int) $cart['id'], $itemId);
        if ($row === null) {
            throw new ValidationException(['cart' => ['Sepet kalemi bulunamadı.']]);
        }

        if ($quantity <= 0) {
            $this->items->delete($itemId);
            return;
        }

        $stock = $row['variant_id'] !== null ? (int) $row['variant_stock'] : (int) $row['product_stock'];
        if ($quantity > $stock) {
            throw new ValidationException(['quantity' => [
                'Stok yetersiz — en fazla ' . max(0, $stock) . ' adet seçebilirsiniz.',
            ]]);
        }

        $this->items->updateQuantity($itemId, $quantity);
    }

    public function remove(int $itemId): void
    {
        $cart = $this->resolve(false);
        if ($cart === null) {
            return;
        }
        $row = $this->items->findForCart((int) $cart['id'], $itemId);
        if ($row !== null) {
            $this->items->delete($itemId);
        }
    }

    public function clear(): void
    {
        $cart = $this->resolve(false);
        if ($cart !== null) {
            $this->items->clear((int) $cart['id']);
            $this->carts->setCouponCode((int) $cart['id'], null);
        }
    }

    /* ---------------------------------------------------------------------
     * Kupon
     * ------------------------------------------------------------------- */

    /** @return array<string,mixed> uygulanan kupon */
    public function applyCoupon(string $code): array
    {
        $cart = $this->resolve(false);
        if ($cart === null || $this->items->countForCart((int) $cart['id']) === 0) {
            throw new ValidationException(['coupon' => ['Kupon uygulamak için sepetinizde ürün olmalı.']]);
        }

        $summary = $this->summary();
        $coupon = $this->couponService->validate($code, $summary['subtotal']);

        $this->carts->setCouponCode((int) $cart['id'], strtoupper(trim($code)));

        return $coupon;
    }

    public function removeCoupon(): void
    {
        $cart = $this->resolve(false);
        if ($cart !== null) {
            $this->carts->setCouponCode((int) $cart['id'], null);
        }
    }

    /* ---------------------------------------------------------------------
     * Birleştirme (misafir → üye)
     * ------------------------------------------------------------------- */

    public function mergeGuestCartIntoCustomer(int $customerId): void
    {
        $token = $this->ensureToken();
        $guest = $this->carts->findActiveBySession($token);
        if ($guest === null) {
            return;
        }

        $member = $this->carts->findActiveByCustomer($customerId);

        if ($member === null) {
            // Üye sepeti yok → misafir sepeti doğrudan üyeye devredilir
            $this->carts->attachCustomer((int) $guest['id'], $customerId);
            return;
        }

        // Her iki sepet de var → kalemler birleştirilir (stokla sınırlı)
        $memberId = (int) $member['id'];
        foreach ($this->items->forCart((int) $guest['id']) as $gItem) {
            $productId = (int) $gItem['product_id'];
            $variantId = $gItem['variant_id'] !== null ? (int) $gItem['variant_id'] : null;

            $stock = $gItem['variant_id'] !== null ? (int) $gItem['variant_stock'] : (int) $gItem['product_stock'];
            $quantity = (int) $gItem['quantity'];

            $existing = $this->items->findByCartProductVariant($memberId, $productId, $variantId);
            if ($existing !== null) {
                $merged = min(max(0, $stock), (int) $existing['quantity'] + $quantity);
                $this->items->updateQuantity((int) $existing['id'], $merged);
            } else {
                $unitPrice = $gItem['variant_price'] !== null
                    ? (float) $gItem['variant_price']
                    : (float) ($gItem['sale_price'] ?? 0);
                $this->items->insert([
                    'cart_id'    => $memberId,
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity'   => min(max(1, $quantity), max(1, $stock)),
                    'unit_price' => $unitPrice,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Kupon: üye sepetinde kupon yoksa misafir kuponunu taşı
        $memberCoupon = $member['coupon_code'] ?? null;
        if (($memberCoupon === null || $memberCoupon === '') && !empty($guest['coupon_code'])) {
            $this->carts->setCouponCode($memberId, (string) $guest['coupon_code']);
        }

        $this->carts->setStatus((int) $guest['id'], 'converted');
    }

    /* ---------------------------------------------------------------------
     * Özet (SUNUCU TARAFLI hesap)
     * ------------------------------------------------------------------- */

    /**
     * Sepet özeti — tüm tutarlar DB'den taze fiyatla hesaplanır.
     *
     * @return array{
     *   cart_id: ?int, items: list<array<string,mixed>>, count: int,
     *   subtotal: float, discount_total: float, shipping_total: float,
     *   grand_total: float, coupon: ?array<string,mixed>,
     *   free_shipping_remaining: float, has_unavailable: bool,
     *   shipping_fee: float, free_shipping_threshold: float, currency: string
     * }
     */
    public function summary(): array
    {
        $cart = $this->resolve(false);

        $items = [];
        $subtotal = 0.0;
        $count = 0;
        $hasUnavailable = false;

        if ($cart !== null) {
            $staleIds = [];
            $rows = $this->items->forCart((int) $cart['id']);
            $productIds = array_map(static fn ($r) => (int) $r['product_id'], $rows);
            $staleIds = $this->storeProducts->staleProductIds($productIds);

            foreach ($rows as $row) {
                $productId = (int) $row['product_id'];
                $isStale = in_array($productId, $staleIds, true);
                $variant = $row['variant_id'] !== null;

                $unitPrice = $variant
                    ? ProductVariantRepository::effectivePrice($row['variant_price'] ?? null, $row['sale_price'] ?? null)
                    : (float) ($row['sale_price'] ?? 0);
                $stock = $variant ? (int) $row['variant_stock'] : (int) $row['product_stock'];
                $quantity = (int) $row['quantity'];

                $available = $row['status'] === Product::STATUS_ACTIVE
                    && $row['deleted_at'] === null
                    && (!$variant || $row['variant_status'] === 'active')
                    && $stock > 0
                    && $quantity <= $stock
                    && !$isStale;

                $reason = null;
                if ($row['status'] !== Product::STATUS_ACTIVE || $row['deleted_at'] !== null) {
                    $reason = 'Bu ürün artık satışta değil.';
                } elseif ($isStale) {
                    $reason = 'Tedarikçi stok bilgisi güncel değil — satın alma kapalı.';
                } elseif ($stock <= 0) {
                    $reason = 'Bu ürün stokta yok.';
                } elseif ($quantity > $stock) {
                    $reason = 'Stok yetersiz — en fazla ' . $stock . ' adet.';
                }

                $lineTotal = round($unitPrice * $quantity, 2);

                if ($available) {
                    $subtotal += $lineTotal;
                    $count += $quantity;
                } else {
                    $hasUnavailable = true;
                }

                $items[] = [
                    'id'            => (int) $row['id'],
                    'product_id'    => $productId,
                    'variant_id'    => $variant ? (int) $row['variant_id'] : null,
                    'name'          => (string) $row['name'],
                    'variant_label' => $variant ? $this->variantLabel($row) : null,
                    'sku'           => (string) ($row['variant_sku'] ?? $row['sku']),
                    'slug'          => (string) $row['slug'],
                    'image_path'    => $row['image_path'],
                    'quantity'      => $quantity,
                    'unit_price'    => $unitPrice,
                    'line_total'    => $lineTotal,
                    'stock'         => max(0, $stock),
                    'tax_rate'      => (float) $row['tax_rate'],
                    'available'     => $available,
                    'reason'        => $reason,
                ];
            }
        }

        $subtotal = round($subtotal, 2);

        // Kupon (sepette kayıtlıysa yeniden doğrula)
        $coupon = null;
        $discount = 0.0;
        if ($cart !== null && !empty($cart['coupon_code']) && $subtotal > 0) {
            try {
                $coupon = $this->couponService->validate((string) $cart['coupon_code'], $subtotal);
                $discount = $this->couponService->discountFor($coupon, $subtotal);
            } catch (ValidationException) {
                // Kupon artık geçersiz → sessizce düşürülür, indirim uygulanmaz
                $this->carts->setCouponCode((int) $cart['id'], null);
                $coupon = null;
            }
        }

        $settings = new SettingsService();
        $fee = $settings->shippingFee();
        $threshold = $settings->freeShippingThreshold();
        $shipping = 0.0;
        if ($count > 0) {
            $shipping = ($subtotal - $discount) >= $threshold ? 0.0 : round($fee, 2);
        }

        return [
            'cart_id'                  => $cart !== null ? (int) $cart['id'] : null,
            'items'                    => $items,
            'count'                    => $count,
            'subtotal'                 => $subtotal,
            'discount_total'           => $discount,
            'shipping_total'           => $shipping,
            'grand_total'              => round($subtotal - $discount + $shipping, 2),
            'coupon'                   => $coupon,
            'free_shipping_remaining'  => $count > 0 ? max(0.0, round($threshold - ($subtotal - $discount), 2)) : 0.0,
            'has_unavailable'          => $hasUnavailable,
            'shipping_fee'             => round($fee, 2),
            'free_shipping_threshold'  => $threshold,
            'currency'                 => 'TRY',
        ];
    }

    /** Navbar rozeti için toplam adet. */
    public function itemCount(): int
    {
        try {
            $cart = $this->resolve(false);
            return $cart === null ? 0 : $this->items->countForCart((int) $cart['id']);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function variantLabel(array $row): string
    {
        $attrs = $row['variant_attributes'] ?? null;
        if ($attrs !== null && $attrs !== '') {
            $decoded = json_decode((string) $attrs, true);
            if (is_array($decoded) && $decoded !== []) {
                $parts = [];
                foreach ($decoded as $k => $v) {
                    $parts[] = $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
                }
                return implode(' / ', $parts);
            }
        }
        return (string) ($row['variant_sku'] ?? '');
    }
}
