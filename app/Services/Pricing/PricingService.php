<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Core\Config;
use App\Repositories\CategoryRepository;
use App\Repositories\PriceRuleRepository;

/**
 * Fiyatlandırma motoru: alış fiyatından satış fiyatını üretir.
 *
 * Kural tipleri (price_rules.type):
 *  - percent   : yüzde bazlı kâr/fiyat artışı        → sale *= (1 + value/100)
 *  - fixed     : sabit tutar ekleme                  → sale += value
 *  - min_price : minimum satış fiyatı (taban)        → sale = max(sale, value)
 *  - round     : en yakın adıma yuvarlama            → sale = round(sale/value)*value
 *  - round_end : psikolojik fiyat sonu (0.99 vb.)    → sale = floor(sale)-1+value
 *
 * Kapsamlar (price_rules.scope): global | supplier | category | product
 * Uygulama sırası: priority ASC, id ASC. Vergi: apply_tax_to_sale=true ise
 * satış fiyatı KDV dahil hâle getirilir (price_includes_tax=1 ile saklanır).
 *
 * Tam yol: app/Services/Pricing/PricingService.php
 */
final class PricingService
{
    public function __construct(
        private readonly PriceRuleRepository $rules = new PriceRuleRepository(),
        private readonly CategoryRepository $categories = new CategoryRepository()
    ) {
    }

    /**
     * @param array<int,int|null>|null $categoryParentMap id → parent_id (kategori zinciri kuralı için)
     * @return array{sale_price:float, margin:float, low_margin:bool, applied:list<string>, tax_rate:?float}
     */
    public function compute(
        float $costTry,
        ?int $categoryId = null,
        ?int $supplierId = null,
        ?int $productId = null,
        float $vatRate = 20.0,
        ?array $categoryParentMap = null
    ): array {
        $sale = $costTry;
        $applied = [];

        foreach ($this->rules->activeRules() as $rule) {
            if (!$this->ruleMatches($rule, $categoryId, $supplierId, $productId, $categoryParentMap)) {
                continue;
            }

            $value = (float) $rule['value'];
            $type = (string) $rule['type'];
            $label = sprintf('#%d %s', (int) $rule['id'], (string) $rule['name']);

            switch ($type) {
                case 'percent':
                    if ($value !== 0.0) {
                        $sale = $sale * (1 + $value / 100);
                        $applied[] = $label . ' (+' . $value . '%)';
                    }
                    break;

                case 'fixed':
                    if ($value !== 0.0) {
                        $sale += $value;
                        $applied[] = $label . ' (+' . $value . ' TRY)';
                    }
                    break;

                case 'min_price':
                    if ($sale < $value) {
                        $sale = $value;
                        $applied[] = $label . ' (taban ' . $value . ')';
                    }
                    break;

                case 'round':
                    if ($value > 0) {
                        $sale = round($sale / $value) * $value;
                        $applied[] = $label . ' (yuvarlama ' . $value . ')';
                    }
                    break;

                case 'round_end':
                    $ending = $value > 0 ? $value : 0.99;
                    $sale = floor($sale) - 1 + $ending;
                    $applied[] = $label . ' (x,' . rtrim(rtrim((string) $ending, '0'), '.') . ' sonu)';
                    break;
            }

            if ($sale < 0) {
                $sale = 0.0;
            }
        }

        $sale = round($sale, 2);

        // Vergi modellemesi: opsiyonel olarak satış fiyatı KDV dahil hâle getirilir
        $applyTax = (bool) Config::get('xml.pricing.apply_tax_to_sale', false);
        $finalSale = $applyTax ? round($sale * (1 + $vatRate / 100), 2) : $sale;

        $margin = $finalSale > 0 ? (($finalSale - $costTry) / $finalSale) * 100 : 0.0;
        $warnThreshold = (float) Config::get('xml.pricing.profit_warn_percent', 10.0);

        return [
            'sale_price' => $finalSale,
            'margin'     => round($margin, 2),
            'low_margin' => $margin < $warnThreshold,
            'applied'    => $applied,
            'tax_rate'   => $vatRate,
        ];
    }

    /**
     * Kural kapsamı eşleşmesi. Kategori kuralı üst kategori zincirine de uygulanır
     * (parent kategorideki kural alt kategorideki ürünü kapsar).
     *
     * @param array<string,mixed> $rule
     * @param array<int,int|null>|null $categoryParentMap
     */
    private function ruleMatches(
        array $rule,
        ?int $categoryId,
        ?int $supplierId,
        ?int $productId,
        ?array $categoryParentMap
    ): bool {
        $scope = (string) $rule['scope'];

        // Ürün bazlı kural yalnız o ürün için
        if ($rule['product_id'] !== null && (int) $rule['product_id'] !== (int) $productId) {
            return false;
        }

        return match ($scope) {
            'global'   => $rule['product_id'] === null,
            'supplier' => $rule['supplier_id'] !== null && (int) $rule['supplier_id'] === (int) $supplierId,
            'category' => $this->categoryMatches($rule, $categoryId, $categoryParentMap),
            'product'  => $rule['product_id'] !== null && (int) $rule['product_id'] === (int) $productId,
            default    => false,
        };
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<int,int|null>|null $categoryParentMap
     */
    private function categoryMatches(array $rule, ?int $categoryId, ?array $categoryParentMap): bool
    {
        if ($rule['category_id'] === null || $categoryId === null) {
            return false;
        }
        $ruleCategoryId = (int) $rule['category_id'];

        $current = $categoryId;
        $guard = 0;
        while ($current !== null && $current > 0 && $guard < 20) {
            if ($current === $ruleCategoryId) {
                return true;
            }
            $current = $categoryParentMap !== null
                ? ($categoryParentMap[$current] ?? null)
                : null;
            $guard++;
        }
        return false;
    }
}
