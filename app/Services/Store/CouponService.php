<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Exceptions\ValidationException;
use App\Repositories\CouponRepository;

/**
 * Kupon doğrulama + indirim hesabı. Tüm kurallar sunucu tarafında işler;
 * istemciden gelen indirim tutarına asla güvenilmez.
 *
 * Tam yol: app/Services/Store/CouponService.php
 */
final class CouponService
{
    public function __construct(private ?CouponRepository $coupons = null)
    {
        $this->coupons ??= new CouponRepository();
    }

    /**
     * Kuponu araştırır; geçersizse ValidationException fırlatır.
     *
     * @return array<string,mixed> coupon satırı
     */
    public function validate(string $code, float $subtotal): array
    {
        $coupon = $this->coupons->findByCode($code);

        if ($coupon === null) {
            throw new ValidationException(['coupon' => ['Kupon kodu bulunamadı.']]);
        }
        if ((int) $coupon['is_active'] !== 1) {
            throw new ValidationException(['coupon' => ['Bu kupon artık geçerli değil.']]);
        }
        if ($coupon['starts_at'] !== null && strtotime((string) $coupon['starts_at']) > time()) {
            throw new ValidationException(['coupon' => ['Bu kupon henüz aktif olmadı.']]);
        }
        if ($coupon['expires_at'] !== null && strtotime((string) $coupon['expires_at']) < time()) {
            throw new ValidationException(['coupon' => ['Bu kuponun süresi dolmuş.']]);
        }
        if ($coupon['usage_limit'] !== null && (int) $coupon['used_count'] >= (int) $coupon['usage_limit']) {
            throw new ValidationException(['coupon' => ['Bu kuponun kullanım limiti dolmuş.']]);
        }
        if ($coupon['min_order_total'] !== null && $subtotal < (float) $coupon['min_order_total']) {
            throw new ValidationException(['coupon' => [
                'Bu kupon için minimum sepet tutarı ' . money($coupon['min_order_total']) . ' olmalıdır.',
            ]]);
        }

        return $coupon;
    }

    /** İndirim tutarı (subtotal ile sınırlı, 2 ondalık). */
    public function discountFor(array $coupon, float $subtotal): float
    {
        $discount = 0.0;
        if ($coupon['type'] === 'percent') {
            $discount = round($subtotal * ((float) $coupon['value']) / 100, 2);
        } else {
            $discount = round((float) $coupon['value'], 2);
        }

        if ($coupon['max_discount'] !== null) {
            $discount = min($discount, (float) $coupon['max_discount']);
        }

        return round(min($discount, $subtotal), 2);
    }

    public function incrementUsage(int $couponId): void
    {
        $this->coupons->incrementUsage($couponId);
    }
}
