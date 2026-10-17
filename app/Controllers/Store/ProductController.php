<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Store\StoreProductService;

/**
 * Ürün detay sayfası (PDP) — ürün, benzer ürünler ve Product/BreadcrumbList
 * JSON-LD yapılandırılmış verisi.
 *
 * Tam yol: app/Controllers/Store/ProductController.php
 */
final class ProductController
{
    private StoreProductService $products;

    public function __construct(?StoreProductService $products = null)
    {
        $this->products = $products ?? new StoreProductService();
    }

    public function show(Request $request, string $slug): Response
    {
        $product = $this->products->productForStore($slug);
        if ($product === null) {
            abort(404, 'Ürün bulunamadı.');
        }

        $categoryId = $product['category_id'] !== null ? (int) $product['category_id'] : null;
        $related = $this->products->related((int) $product['id'], $categoryId, 4);

        $canonical = request_base_url() . '/urun/' . rawurlencode((string) $product['slug']);

        $title = trim((string) ($product['meta_title'] ?? ''));
        if ($title === '') {
            $title = (string) $product['name'];
        }

        $metaDescription = trim((string) ($product['meta_description'] ?? ''));
        if ($metaDescription === '') {
            $description = trim((string) ($product['description'] ?? ''));
            $metaDescription = $description !== ''
                ? mb_substr($description, 0, 155)
                : (string) $product['name'];
        }

        return View::render('store/product/show', [
            'title'           => $title,
            'metaDescription' => $metaDescription,
            'canonical'       => $canonical,
            'jsonLd'          => $this->buildJsonLd($product, $canonical),
            'product'         => $product,
            'related'         => $related,
        ], 'layouts/store');
    }

    /* -----------------------------------------------------------------
     * JSON-LD (schema.org) — layout ham basar; üretici tarafı kaçışlar
     * json_encode ile yapılır.
     * ----------------------------------------------------------------- */

    /**
     * Product + BreadcrumbList graph'ı üretir.
     *
     * @param array<string,mixed> $product
     */
    private function buildJsonLd(array $product, string $canonical): string
    {
        $base = request_base_url();

        $salePrice = (float) (($product['sale_price'] ?? null) !== null && $product['sale_price'] !== ''
            ? $product['sale_price']
            : ($product['list_price'] ?? 0));

        // Spec: stock>0 && purchasable ? InStock : OutOfStock
        $inStock = (int) ($product['stock'] ?? 0) > 0
            && !empty($product['purchasable']);

        $images = [];
        foreach ((array) ($product['images'] ?? []) as $image) {
            $path = (string) ($image['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $images[] = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
                ? $path
                : $base . $path;
        }

        $productNode = [
            '@type'       => 'Product',
            'name'        => (string) $product['name'],
            'sku'         => (string) ($product['sku'] ?? ''),
            'url'         => $canonical,
            'offers'      => [
                '@type'        => 'Offer',
                'price'        => $salePrice,
                'priceCurrency' => 'TRY',
                'availability' => $inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url'          => $canonical,
            ],
        ];
        if (($brand = trim((string) ($product['brand'] ?? ''))) !== '') {
            $productNode['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }
        if ($images !== []) {
            $productNode['image'] = $images;
        }

        $items = [[
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => 'Ana Sayfa',
            'item'     => $base . '/',
        ]];
        $position = 2;
        foreach ((array) ($product['breadcrumb'] ?? []) as $category) {
            $catSlug = (string) ($category['slug'] ?? '');
            if ($catSlug === '') {
                continue;
            }
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $position++,
                'name'     => (string) ($category['name'] ?? $catSlug),
                'item'     => $base . '/kategori/' . rawurlencode($catSlug),
            ];
        }
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $position,
            'name'     => (string) $product['name'],
            'item'     => $canonical,
        ];

        $graph = [
            '@context' => 'https://schema.org',
            '@graph'   => [
                $productNode,
                [
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => $items,
                ],
            ],
        ];

        // AŞAMA 6: JSON_HEX_TAG|JSON_HEX_AMP — XML feed'inden gelen ad/marka içindeki
        // "</script>" dizisinin JSON-LD script bloğundan kaçmasına karşı (2. mertebe XSS);
        // \u003C sekansları JSON spec'e göre geçerli, JSON-LD tüketen tarayıcılar için şeffaf.
        $json = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return $json === false ? '' : (string) $json;
    }
}
