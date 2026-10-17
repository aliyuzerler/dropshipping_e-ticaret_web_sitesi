<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Core\Logger;
use App\Core\Session;
use App\Exceptions\ValidationException;
use App\Repositories\CartItemRepository;
use App\Repositories\CartRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\AddressRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductSupplierDataRepository;
use Throwable;

/**
 * Sipariş oluşturma (checkout) — ÖDEME YOK (Aşama 4).
 *
 * Güvenlik/istikrar ilkeleri:
 *  - Tüm fiyatlar sepetten değil, transaction içinde kilitli ürün satırından alınır.
 *  - Stok tekrar doğrulanır ve transaction içinde düşülür (race-safe).
 *  - Kupon yeniden doğrulanır; geçersizse sipariş REDDEDİLİR (sessiz indirim yok).
 *  - Adres + ürün verileri snapshot olarak kalıcı yazılır.
 *
 * Tam yol: app/Services/Store/CheckoutService.php
 */
final class CheckoutService
{
    private CartService $cart;

    private CartItemRepository $items;

    private CartRepository $carts;

    private OrderRepository $orders;

    private CustomerRepository $customers;

    private AddressRepository $addresses;

    private SettingsService $settings;

    private CouponService $couponService;

    private StoreProductService $storeProducts;

    private ProductSupplierDataRepository $supplierData;

    public function __construct(
        ?CartService $cart = null,
        ?OrderRepository $orders = null,
        ?CustomerRepository $customers = null,
        ?AddressRepository $addresses = null,
        ?CartItemRepository $items = null,
        ?CartRepository $carts = null,
        ?SettingsService $settings = null,
        ?CouponService $couponService = null,
        ?StoreProductService $storeProducts = null,
        ?ProductSupplierDataRepository $supplierData = null
    ) {
        $this->cart = $cart ?? new CartService();
        $this->orders = $orders ?? new OrderRepository();
        $this->customers = $customers ?? new CustomerRepository();
        $this->addresses = $addresses ?? new AddressRepository();
        $this->items = $items ?? new CartItemRepository();
        $this->carts = $carts ?? new CartRepository();
        $this->settings = $settings ?? new SettingsService();
        $this->couponService = $couponService ?? new CouponService();
        $this->storeProducts = $storeProducts ?? new StoreProductService();
        $this->supplierData = $supplierData ?? new ProductSupplierDataRepository();
    }

    /**
     * Sipariş oluşturur. $input beklenen alanlar:
     *  - address_mode: 'existing' | 'new'
     *  - address_id (existing için), yeni adres: full_name, country_code, city,
     *    district, postal_code, line1, line2, phone, save_address (opsiyonel)
     *  - note, terms ('1')
     *
     * @return array{order: array<string,mixed>, number: string, order_id: int}
     */
    public function placeOrder(array $input, string $ip, string $userAgent): array
    {
        $customerId = Session::get('customer_id');
        if (!is_int($customerId) || $customerId <= 0) {
            throw new ValidationException(['checkout' => ['Sipariş vermek için giriş yapmalısınız.']]);
        }

        $errors = [];
        if (($input['terms'] ?? '') !== '1') {
            $errors['terms'] = ['Mesafeli satış sözleşmesini onaylamanız gerekir.'];
        }

        $shipping = $this->resolveShippingAddress($input, $customerId, $errors);
        $note = mb_substr(trim((string) ($input['note'] ?? '')), 0, 1000, 'UTF-8');

        $cart = $this->cart->resolve(false);
        if ($cart === null || $this->items->countForCart((int) $cart['id']) === 0) {
            throw new ValidationException(['cart' => ['Sepetiniz boş.']]);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $pdo = \App\Core\Database::pdo();
        $pdo->beginTransaction();

        try {
            // 1) Kalemleri kilitle ve taze fiyat/stok ile doğrula
            $rows = $this->items->forCart((int) $cart['id']);
            if ($rows === []) {
                throw new ValidationException(['cart' => ['Sepetiniz boş.']]);
            }

            $productIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['product_id'], $rows)));
            $variantIds = array_values(array_filter(array_map(
                static fn ($r) => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
                $rows
            ), static fn ($v) => $v !== null));

            $lockProducts = $pdo->prepare('SELECT id, sku, name, stock, status, deleted_at, sale_price FROM products WHERE id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ') FOR UPDATE');
            $lockProducts->execute($productIds);
            $lockedProducts = [];
            foreach ($lockProducts->fetchAll() as $p) {
                $lockedProducts[(int) $p['id']] = $p;
            }

            $lockedVariants = [];
            if ($variantIds !== []) {
                $lockVariants = $pdo->prepare('SELECT id, sku, price, stock, status FROM product_variants WHERE id IN (' . implode(',', array_fill(0, count($variantIds), '?')) . ') FOR UPDATE');
                $lockVariants->execute($variantIds);
                foreach ($lockVariants->fetchAll() as $v) {
                    $lockedVariants[(int) $v['id']] = $v;
                }
            }

            $staleIds = $this->storeProducts->staleProductIds($productIds);

            // 2) Satır doğrulama + toplamlar (sunucu taraflı)
            $itemsToWrite = [];
            $itemsTotal = 0.0;
            $taxTotal = 0.0;

            foreach ($rows as $row) {
                $productId = (int) $row['product_id'];
                $variantId = $row['variant_id'] !== null ? (int) $row['variant_id'] : null;
                $product = $lockedProducts[$productId] ?? null;
                $variant = $variantId !== null ? ($lockedVariants[$variantId] ?? null) : null;

                if ($product === null || $product['status'] !== 'active' || $product['deleted_at'] !== null) {
                    throw new ValidationException(['cart' => ['"' . $row['name'] . '" artık satışta değil. Sepetinizi güncelleyin.']]);
                }
                if (in_array($productId, $staleIds, true)) {
                    throw new ValidationException(['cart' => ['"' . $row['name'] . '" için tedarikçi stok bilgisi güncel değil; satın alma şu anda kapalı.']]);
                }

                $stock = $variant !== null ? (int) $variant['stock'] : (int) $product['stock'];
                $quantity = (int) $row['quantity'];

                if ($variant !== null && $variant['status'] !== 'active') {
                    throw new ValidationException(['cart' => ['"' . $row['name'] . '" seçeneği artık satışta değil.']]);
                }
                if ($quantity < 1) {
                    throw new ValidationException(['cart' => ['"' . $row['name'] . '" için geçersiz adet.']]);
                }
                if ($quantity > $stock) {
                    throw new ValidationException(['cart' => [
                        '"' . $row['name'] . '" için stok yetersiz — en fazla ' . max(0, $stock) . ' adet.',
                    ]]);
                }

                $unitPrice = $variant !== null
                    ? \App\Repositories\ProductVariantRepository::effectivePrice($variant['price'] ?? null, $product['sale_price'] ?? null)
                    : (float) ($product['sale_price'] ?? 0);

                if ($unitPrice <= 0) {
                    // Geçersiz fiyat asla sessizce kabul edilmez
                    throw new ValidationException(['cart' => ['"' . $row['name'] . '" için geçerli bir satış fiyatı bulunamadı.']]);
                }

                $taxRate = (float) $row['tax_rate'];
                $lineTotal = round($unitPrice * $quantity, 2);
                $taxAmount = round($lineTotal - ($lineTotal / (1 + $taxRate / 100)), 2);

                $itemsTotal += $lineTotal;
                $taxTotal += $taxAmount;

                $snapshotName = (string) $product['name'];
                if ($variant !== null) {
                    $attrs = $row['variant_attributes'] ?? null;
                    $label = $attrs !== null && $attrs !== ''
                        ? trim((string) preg_replace('/[\{\}"]/', ' ', (string) $attrs))
                        : (string) $variant['sku'];
                    $snapshotName .= ' — ' . mb_substr($label, 0, 80, 'UTF-8');
                }

                $itemsToWrite[] = [
                    'product_id'            => $productId,
                    'variant_id'            => $variantId,
                    'supplier_id'           => $this->primarySupplierId($productId),
                    'product_name_snapshot' => $snapshotName,
                    'sku_snapshot'          => (string) ($variant['sku'] ?? $product['sku']),
                    'quantity'              => $quantity,
                    'unit_price'            => $unitPrice,
                    'tax_rate'              => $taxRate,
                    'tax_amount'            => $taxAmount,
                    'line_total'            => $lineTotal,
                    'currency'              => 'TRY',
                ];
            }

            $itemsTotal = round($itemsTotal, 2);
            $taxTotal = round($taxTotal, 2);

            // 3) Kupon (yeniden doğrulanır; geçersizse sipariş reddedilir)
            $discount = 0.0;
            $couponCode = null;
            if (!empty($cart['coupon_code'])) {
                $couponCode = strtoupper(trim((string) $cart['coupon_code']));
                $coupon = $this->couponService->validate($couponCode, $itemsTotal);
                $discount = $this->couponService->discountFor($coupon, $itemsTotal);
            }

            // 4) Kargo
            $fee = $this->settings->shippingFee();
            $threshold = $this->settings->freeShippingThreshold();
            $shippingTotal = ($itemsTotal - $discount) >= $threshold ? 0.0 : round($fee, 2);
            $grandTotal = round($itemsTotal - $discount + $shippingTotal, 2);

            // 5) Stok düşümü
            foreach ($itemsToWrite as $item) {
                if ($item['variant_id'] !== null) {
                    $stmt = $pdo->prepare(
                        "UPDATE product_variants SET stock = stock - ?, status = IF(stock - ? <= 0, 'passive', status), updated_at = NOW() WHERE id = ?"
                    );
                    $stmt->execute([$item['quantity'], $item['quantity'], $item['variant_id']]);
                }
                $stmt = $pdo->prepare(
                    "UPDATE products SET stock = stock - ?,
                        stock_status = IF(stock - ? <= 0, 'out_of_stock', 'in_stock'),
                        updated_at = NOW()
                     WHERE id = ?"
                );
                $stmt->execute([$item['quantity'], $item['quantity'], $item['product_id']]);
            }

            // 6) Sipariş + kalemler (snapshot)
            $shippingSnapshot = $shipping + ['shipping_method' => 'standart', 'shipping_total' => $shippingTotal];
            $orderData = [
                'order_number'     => $this->orders->uniqueOrderNumber(),
                'customer_id'      => $customerId,
                'status'           => 'pending_payment',
                'currency'         => 'TRY',
                'items_total'      => $itemsTotal,
                'discount_total'   => $discount,
                'coupon_code'      => $couponCode,
                'shipping_total'   => $shippingTotal,
                'tax_total'        => $taxTotal,
                'grand_total'      => $grandTotal,
                'shipping_method'  => 'standart',
                'shipping_snapshot' => json_encode($shippingSnapshot, JSON_UNESCAPED_UNICODE),
                'billing_snapshot'  => json_encode($shipping, JSON_UNESCAPED_UNICODE),
                'note'             => $note !== '' ? $note : null,
                'ip_address'       => $ip,
                'user_agent'       => mb_substr($userAgent, 0, 255, 'UTF-8'),
                'placed_at'        => date('Y-m-d H:i:s'),
                'created_at'       => date('Y-m-d H:i:s'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ];

            $orderId = $this->orders->placeWithItems($orderData, $itemsToWrite);

            // 7) Kupon kullanımı + sepet kapanışı
            if ($couponCode !== null && $discount > 0) {
                $couponRow = (new \App\Repositories\CouponRepository())->findByCode($couponCode);
                if ($couponRow !== null) {
                    $this->couponService->incrementUsage((int) $couponRow['id']);
                }
            }
            $this->carts->setStatus((int) $cart['id'], 'completed');

            $pdo->commit();

            Logger::info('app', 'order.placed', [
                'order_number' => $orderData['order_number'],
                'customer_id'  => $customerId,
                'grand_total'  => $grandTotal,
            ]);

            // AŞAMA 5: "Sipariş alındı" bildirimi (kuyruk — tekrarsız; ana akışı bozmaz)
            \App\Services\Notification\NotificationService::orderReceived(
                $this->orders->find($orderId) ?? array_merge($orderData, ['id' => $orderId])
            );

            return [
                'order_id' => $orderId,
                'number'   => (string) $orderData['order_number'],
                'order'    => $this->orders->find($orderId) ?? $orderData,
            ];
        } catch (ValidationException $e) {
            $pdo->rollBack();
            throw $e;
        } catch (Throwable $e) {
            $pdo->rollBack();
            Logger::error('app', 'checkout.failed', ['message' => $e->getMessage()]);
            throw new ValidationException(['checkout' => ['Sipariş oluşturulamadı. Lütfen tekrar deneyin.']]);
        }
    }

    /**
     * Teslimat adresini çözümler: kayıtlı adres seçimi veya yeni adres doğrulaması.
     *
     * @param list<string> $errors (referansla birikir)
     * @return array<string,mixed> adres snapshot'ı
     */
    private function resolveShippingAddress(array $input, int $customerId, array &$errors): array
    {
        $mode = ($input['address_mode'] ?? 'new') === 'existing' ? 'existing' : 'new';

        if ($mode === 'existing') {
            $addressId = (int) ($input['address_id'] ?? 0);
            $address = $this->addresses->findForCustomer($addressId, $customerId);
            if ($address === null) {
                $errors['address'] = ['Lütfen geçerli bir teslimat adresi seçin.'];
                return [];
            }
            return [
                'title'       => $address['title'],
                'full_name'   => $address['full_name'],
                'country_code' => $address['country_code'],
                'city'        => $address['city'],
                'district'    => $address['district'],
                'postal_code' => $address['postal_code'],
                'line1'       => $address['line1'],
                'line2'       => $address['line2'],
                'phone'       => $address['phone'],
            ];
        }

        // Yeni adres formu
        $full_name = trim((string) ($input['full_name'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $district = trim((string) ($input['district'] ?? ''));
        $line1 = trim((string) ($input['line1'] ?? ''));
        $postal = trim((string) ($input['postal_code'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));

        if ($full_name === '' || mb_strlen($full_name, 'UTF-8') > 160) {
            $errors['full_name'] = ['Ad Soyad zorunludur.'];
        }
        if ($city === '' || mb_strlen($city, 'UTF-8') > 80) {
            $errors['city'] = ['İl zorunludur.'];
        }
        if ($district === '' || mb_strlen($district, 'UTF-8') > 80) {
            $errors['district'] = ['İlçe zorunludur.'];
        }
        if ($line1 === '' || mb_strlen($line1, 'UTF-8') > 255) {
            $errors['line1'] = ['Açık adres zorunludur (en fazla 255 karakter).'];
        }
        if ($postal !== '' && mb_strlen($postal, 'UTF-8') > 16) {
            $errors['postal_code'] = ['Posta kodu en fazla 16 karakter olabilir.'];
        }
        if ($phone !== '' && mb_strlen($phone, 'UTF-8') > 40) {
            $errors['phone'] = ['Telefon en fazla 40 karakter olabilir.'];
        }

        $address = [
            'title'        => 'Teslimat Adresi',
            'full_name'    => $full_name,
            'country_code' => 'TR',
            'city'         => $city,
            'district'     => $district,
            'postal_code'  => $postal !== '' ? $postal : null,
            'line1'        => $line1,
            'line2'        => trim((string) ($input['line2'] ?? '')) ?: null,
            'phone'        => $phone !== '' ? $phone : null,
        ];

        // Adres defterine kaydet (isteğe bağlı)
        if (($input['save_address'] ?? '') === '1' && $errors === []) {
            $this->addresses->createForCustomer($customerId, [...$address, 'type' => 'shipping', 'is_default' => 0]);
        }

        return $address;
    }

    private function primarySupplierId(int $productId): ?int
    {
        try {
            $rows = $this->supplierData->forProduct($productId);
            return $rows === [] ? null : (int) $rows[0]['supplier_id'];
        } catch (Throwable) {
            return null;
        }
    }
}
