<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Product;
use App\Repositories\CategoryRepository;
use App\Repositories\PriceHistoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductSupplierDataRepository;
use App\Services\Pricing\PricingService;

/**
 * Ürün eşleştirme ve senkronizasyon çekirdeği (satır bazlı, idempotent).
 *
 * Kurallar:
 *  - Eşleştirme: (supplier_id, supplier_sku) üzerinden product_supplier_data
 *  - Yoksa ürün oluşturulur (kopya oluşturmaz — aynı XML tekrar işlenirse günceller)
 *  - Fiyat/stok güncellemeleri feed ayarlarına + alan koruma bayraklarına saygılı
 *  - Açıklama/görseller feed'in update_* kurallarına göre güncellenir
 *  - Geçersiz fiyat/stok/para birimi sessizce kabul edilmez → satır hatası
 *
 * Tam yol: app/Services/Import/ProductSyncService.php
 */
final class ProductSyncService
{
    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly ProductSupplierDataRepository $psd = new ProductSupplierDataRepository(),
        private readonly CategoryRepository $categories = new CategoryRepository(),
        private readonly PriceHistoryRepository $history = new PriceHistoryRepository(),
        private readonly PricingService $pricing = new PricingService()
    ) {
    }

    /**
     * Tek normalize edilmiş satırı senkronize eder.
     *
     * @param array<string,mixed> $feed xml_feeds satırı
     * @param array{supplier_sku:?string, barcode:?string, name:?string, description:?string,
     *              category:?string, brand:?string, cost:?float, cost_original:?float,
     *              source_currency:?string, vat:?float, stock:?int,
     *              images:list<string>, variants:list<string>} $data FieldMapper çıktısı
     * @param array{supplier_id:int, import_log_id:int, started_at:string,
     *              category_parent_map:array<int,int|null>} $ctx
     * @param array<string,mixed>|null $psdRow Eşleşen mevcut product_supplier_data satırı (null = yeni ürün)
     * @return array{result:'created'|'updated'|'skipped', psd:?array<string,mixed>, low_margin:bool}
     *
     * @throws RowException doğrulama/eşleştirme hatası (satır sayılır, aktarım devam eder)
     */
    public function syncRow(array $feed, array $data, array $ctx, ?array $psdRow): array
    {
        $supplierId = (int) $ctx['supplier_id'];
        $feedId = (int) $feed['id'];

        // ---- 1) Zorunlu alan doğrulaması (sessizce kabul yok) ----
        $sku = trim((string) ($data['supplier_sku'] ?? ''));
        if ($sku === '') {
            throw new RowException('Tedarikçi ürün kodu (supplier_sku) boş.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RowException(sprintf('Ürün adı boş (sku: %s).', $sku));
        }

        $cost = $data['cost'] ?? null;
        if ($cost !== null) {
            if (!is_finite($cost) || $cost < 0) {
                throw new RowException(sprintf('Geçersiz alış fiyatı: %s (sku: %s).', var_export($cost, true), $sku));
            }
        }
        $stock = $data['stock'] ?? null;
        if ($stock !== null && (!is_int($stock) || $stock < 0)) {
            throw new RowException(sprintf('Geçersiz stok değeri: %s (sku: %s).', var_export($stock, true), $sku));
        }
        if (($data['cost_original'] ?? null) !== null && $cost === null) {
            // FieldMapper para birimini çeviremedi
            throw new RowException(sprintf(
                'Bilinmeyen para birimi: %s (sku: %s).',
                (string) ($data['source_currency'] ?? '?'), $sku
            ));
        }

        $vat = ($data['vat'] ?? null) ?? 20.0;
        $sourceCurrency = ($data['source_currency'] ?? 'TRY') ?: 'TRY';

        // ---- 2) Kategori ----
        $categoryId = $feed['default_category_id'] !== null ? (int) $feed['default_category_id'] : null;
        $categoryName = trim((string) ($data['category'] ?? ''));
        if ($categoryName !== '') {
            $category = $this->categories->ensureByName($categoryName);
            if ($category !== [] && isset($category['id'])) {
                $categoryId = (int) $category['id'];
            }
        }

        // ---- 3) Eşleştirme / oluşturma ----
        $isCreate = $psdRow === null;
        $now = date('Y-m-d H:i:s');

        if ($isCreate) {
            $productSku = $this->resolveProductSku($feed, $sku);

            // Ürün SKU'su başka bir ürüne aitse kopya/karışıklık yaratma
            $existing = $this->products->findBySku($productSku);
            if ($existing !== null) {
                $existingPsd = $this->psd->findBySupplierSku($supplierId, $sku);
                if ($existingPsd === null || (int) $existingPsd['product_id'] !== (int) $existing['id']) {
                    throw new RowException(sprintf(
                        'SKU çakışması: "%s" ürün kodu farklı bir ürün tarafından kullanılıyor.',
                        $productSku
                    ));
                }
                $psdRow = $existingPsd;
                $isCreate = false;
            }
        }

        $pricingResult = null;
        $lowMargin = false;

        if ($isCreate) {
            // Yeni ürün: fiyat hesapla (alış yoksa satış 0)
            $productSku = $productSku ?? $this->resolveProductSku($feed, $sku);
            if ($cost !== null) {
                $pricingResult = $this->pricing->compute(
                    $cost, $categoryId, $supplierId, null, (float) $vat, $ctx['category_parent_map']
                );
                $salePrice = $pricingResult['sale_price'];
                $lowMargin = $pricingResult['low_margin'];
            } else {
                $salePrice = null;
            }

            $productId = $this->products->insert([
                'category_id'        => $categoryId,
                'sku'                => $productSku,
                'slug'               => $this->uniqueProductSlug($name, $productSku),
                'name'               => mb_substr($name, 0, 255),
                'description'        => $data['description'],
                'brand'              => $data['brand'] !== null ? mb_substr((string) $data['brand'], 0, 120) : null,
                'barcode'            => $data['barcode'],
                'currency'           => 'TRY',
                'list_price'         => $salePrice,
                'sale_price'         => $salePrice,
                'tax_rate'           => $vat,
                'price_includes_tax' => ((bool) \App\Core\Config::get('xml.pricing.apply_tax_to_sale', false)) ? 1 : 0,
                'stock'              => $stock ?? 0,
                'stock_status'       => ($stock ?? 0) > 0 ? Product::STOCK_IN_STOCK : Product::STOCK_OUT_OF_STOCK,
                'status'             => ((int) ($feed['auto_publish'] ?? 0)) === 1 ? Product::STATUS_ACTIVE : Product::STATUS_DRAFT,
                'source_type'        => Product::SOURCE_XML,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            $this->psd->insert([
                'product_id'       => $productId,
                'supplier_id'      => $supplierId,
                'xml_feed_id'      => $feedId,
                'supplier_sku'     => $sku,
                'supplier_price'   => $data['cost_original'] ?? 0,
                'supplier_currency'=> $sourceCurrency,
                'stock'            => $stock,
                'stock_updated_at' => $stock !== null ? $now : null,
                'supplier_status'  => 'active',
                'last_seen_at'     => $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            if ($data['images'] !== []) {
                $this->replaceImages((int) $productId, $data['images']);
            }
            if ($data['variants'] !== []) {
                $this->replaceVariants((int) $productId, $productSku, $data['variants']);
            }

            $psdRow = $this->psd->findBySupplierSku($supplierId, $sku);
            return ['result' => 'created', 'psd' => $psdRow, 'low_margin' => $lowMargin,
                    'product_id' => $psdRow !== null ? (int) $psdRow['product_id'] : $productId];
        }

        // ---- 4) Güncelleme ----
        $productId = (int) $psdRow['product_id'];
        $product = $this->products->find($productId);
        if ($product === null) {
            throw new RowException(sprintf('Eşleşen ürün bulunamadı (id: %d, sku: %s).', $productId, $sku));
        }

        $psdUpdate = [];
        $productUpdate = ['updated_at' => $now];

        // Tedarikçi verisi her zaman tazelenir
        if ($data['cost_original'] !== null) {
            $psdUpdate['supplier_price'] = $data['cost_original'];
            $psdUpdate['supplier_currency'] = $sourceCurrency;
        }
        if ($stock !== null) {
            $psdUpdate['stock'] = $stock;
            $psdUpdate['stock_updated_at'] = $now;
        }
        $psdUpdate['xml_feed_id'] = $feedId;
        $psdUpdate['supplier_status'] = 'active';
        $psdUpdate['last_seen_at'] = $now;

        $protectStock = ((int) ($psdRow['protect_stock'] ?? 0)) === 1;
        $protectPrice = ((int) ($psdRow['protect_price'] ?? 0)) === 1;
        $protectName = ((int) ($psdRow['protect_name'] ?? 0)) === 1;
        $protectDescription = ((int) ($psdRow['protect_description'] ?? 0)) === 1;
        $protectImages = ((int) ($psdRow['protect_images'] ?? 0)) === 1;

        // Stok güncellemesi
        $stockChanged = false;
        if ($stock !== null
            && ((int) ($feed['update_stock'] ?? 1)) === 1
            && !$protectStock
            && (int) $product['stock'] !== $stock) {
            $productUpdate['stock'] = $stock;
            $productUpdate['stock_status'] = $stock > 0 ? Product::STOCK_IN_STOCK : Product::STOCK_OUT_OF_STOCK;
            $stockChanged = true;
        }

        // Fiyat güncellemesi (kural motoru ile yeniden hesap)
        $priceChanged = false;
        if ($cost !== null
            && ((int) ($feed['update_price'] ?? 1)) === 1
            && !$protectPrice) {
            $pricingResult = $this->pricing->compute(
                $cost, $categoryId, $supplierId, $productId, (float) $vat, $ctx['category_parent_map']
            );
            $newSale = $pricingResult['sale_price'];
            $lowMargin = $pricingResult['low_margin'];

            if ((float) ($product['sale_price'] ?? 0) !== $newSale
                || (float) ($product['tax_rate'] ?? 0) !== (float) $vat) {
                $productUpdate['sale_price'] = $newSale;
                $productUpdate['list_price'] = $newSale;
                $productUpdate['tax_rate'] = $vat;
                $priceChanged = true;
            }
        }

        // İçerik güncellemeleri (yapılandırılabilir kurallar + alan koruması)
        if (((int) ($feed['update_description'] ?? 1)) === 1) {
            if (!$protectName && trim((string) $product['name']) !== $name) {
                $productUpdate['name'] = mb_substr($name, 0, 255);
            }
            if (!$protectDescription
                && $data['description'] !== null
                && (string) ($product['description'] ?? '') !== (string) $data['description']) {
                $productUpdate['description'] = $data['description'];
            }
        }
        if ($data['brand'] !== null && (string) ($product['brand'] ?? '') !== (string) $data['brand']) {
            $productUpdate['brand'] = mb_substr((string) $data['brand'], 0, 120);
        }
        if ($data['barcode'] !== null && (string) ($product['barcode'] ?? '') !== (string) $data['barcode']) {
            $productUpdate['barcode'] = $data['barcode'];
        }
        if ($categoryId !== null && (int) ($product['category_id'] ?? 0) !== $categoryId) {
            $productUpdate['category_id'] = $categoryId;
        }

        $this->products->update($productId, $productUpdate);
        if ($psdUpdate !== []) {
            $psdUpdate['updated_at'] = $now;
            $this->psd->update((int) $psdRow['id'], $psdUpdate);
        }

        // Görseller (yapılandırılabilir kural + koruma)
        if ($data['images'] !== [] && ((int) ($feed['update_images'] ?? 1)) === 1 && !$protectImages) {
            $this->replaceImages($productId, $data['images']);
        }
        if ($data['variants'] !== []) {
            $this->replaceVariants($productId, (string) $product['sku'], $data['variants']);
        }

        // Fiyat/stok değişim geçmişi
        if ($priceChanged || $stockChanged) {
            $this->history->addChange([
                'product_id'     => $productId,
                'supplier_id'    => $supplierId,
                'xml_feed_id'    => $feedId,
                'import_log_id'  => $ctx['import_log_id'],
                'old_cost'       => $psdRow['supplier_price'] !== null ? (float) $psdRow['supplier_price'] : null,
                'new_cost'       => $data['cost_original'],
                'old_sale_price' => $product['sale_price'] !== null ? (float) $product['sale_price'] : null,
                'new_sale_price' => $priceChanged ? ($productUpdate['sale_price'] ?? null) : ($product['sale_price'] !== null ? (float) $product['sale_price'] : null),
                'old_stock'      => (int) $product['stock'],
                'new_stock'      => $stockChanged ? $stock : (int) $product['stock'],
                'currency'       => 'TRY',
                'profit_margin'  => $pricingResult['margin'] ?? null,
                'is_low_margin'  => $lowMargin,
            ]);
        }

        return ['result' => 'updated', 'psd' => null, 'low_margin' => $lowMargin, 'product_id' => $productId];
    }

    /** Ürün SKU'su: feed öneki > tedarikçi kodu öneki > düz SKU. Önek/SKU arasına tek tire konur. */
    private function resolveProductSku(array $feed, string $supplierSku): string
    {
        $prefix = rtrim(trim((string) ($feed['sku_prefix'] ?? '')), '-');
        if ($prefix !== '') {
            return mb_substr($prefix, 0, 20) . '-' . $supplierSku;
        }
        return mb_substr($supplierSku, 0, 64);
    }

    private function uniqueProductSlug(string $name, string $sku): string
    {
        $base = $this->slugify($name !== '' ? $name : $sku);
        $slug = $base;
        $i = 1;
        while ($this->products->findBySlug($slug) !== null) {
            $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 4) . ($i > 1 ? '-' . $i : '');
            $i++;
            if ($i > 20) {
                $slug = $base . '-' . bin2hex(random_bytes(4));
                break;
            }
        }
        return $slug;
    }

    private function slugify(string $text): string
    {
        $map = ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
                'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u'];
        $text = strtolower(strtr($text, $map));
        $text = (string) preg_replace('~[^a-z0-9]+~', '-', $text);
        $slug = trim($text, '-');
        return $slug !== '' ? mb_substr($slug, 0, 240) : 'urun-' . bin2hex(random_bytes(3));
    }

    /** Görselleri feed sırasıyla değiştirir (checksum UNIQUE — aynı URL tekrar eklenmez). */
    private function replaceImages(int $productId, array $images): void
    {
        $pdo = \App\Core\Database::pdo();
        $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);

        $stmt = $pdo->prepare(
            'INSERT INTO product_images (product_id, path, source_url, checksum, is_primary, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $position = 0;
        $seen = [];
        foreach ($images as $url) {
            $url = trim((string) $url);
            if ($url === '' || strlen($url) > 1000) {
                continue;
            }
            $checksum = hash('sha256', $url);
            if (isset($seen[$checksum])) {
                continue;
            }
            $seen[$checksum] = true;
            $stmt->execute([
                $productId,
                mb_substr($url, 0, 500),
                $url,
                $checksum,
                $position === 0 ? 1 : 0,
                $position,
            ]);
            $position++;
        }
    }

    /** Varyantları değiştirir; değerler "renk:Kırmızı" veya düz "XL" olabilir. */
    private function replaceVariants(int $productId, string $productSku, array $variants): void
    {
        $pdo = \App\Core\Database::pdo();
        $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$productId]);

        $stmt = $pdo->prepare(
            'INSERT INTO product_variants (product_id, sku, attributes, status, created_at, updated_at)
             VALUES (?, ?, ?, "active", NOW(), NOW())'
        );
        $seq = 0;
        $seenSkus = [];
        foreach ($variants as $variant) {
            $variant = trim((string) $variant);
            if ($variant === '') {
                continue;
            }
            $attributes = $variant;
            if (str_contains($variant, ':')) {
                [$k, $v] = explode(':', $variant, 2);
                $attributes = json_encode(
                    [trim($k) => trim($v)],
                    JSON_UNESCAPED_UNICODE
                ) ?: $variant;
            } else {
                $attributes = json_encode(['varyant' => $variant], JSON_UNESCAPED_UNICODE) ?: $variant;
            }

            $variantSku = mb_substr($productSku, 0, 55) . '-V' . (++$seq);
            if (isset($seenSkus[$variantSku])) {
                continue;
            }
            $seenSkus[$variantSku] = true;

            try {
                $stmt->execute([$productId, $variantSku, $attributes]);
            } catch (\PDOException) {
                // Varyant SKU çakışması ürünü bloklamasın — varyant atlanır
                continue;
            }
        }
    }
}
