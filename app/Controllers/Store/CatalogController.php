<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Store\StoreProductService;

/**
 * Katalog: kategori listeleme + arama. İki aksiyon da aynı listing
 * view'ını kullanır (mode: 'category' | 'search').
 *
 * Tam yol: app/Controllers/Store/CatalogController.php
 */
final class CatalogController
{
    private const PER_PAGE = 12;

    /** StoreProductService::orderBy() beyaz listesiyle aynı. */
    private const SORTS = ['newest', 'price_asc', 'price_desc', 'name_asc', 'stock_desc'];

    private StoreProductService $products;

    public function __construct(?StoreProductService $products = null)
    {
        $this->products = $products ?? new StoreProductService();
    }

    public function category(Request $request, string $slug): Response
    {
        $category = $this->products->categoryBySlug($slug);
        if ($category === null) {
            abort(404, 'Kategori bulunamadı.');
        }

        $filters = $this->filtersFromQuery($request);
        $filters['category'] = $slug;
        $page = $this->pageFromQuery($request);

        $result = $this->products->search($filters, self::PER_PAGE, $page);
        $breadcrumb = $this->products->breadcrumb($category);

        return View::render('store/catalog/listing', [
            'title'           => (string) $category['name'],
            'metaDescription' => trim((string) $category['name']) . ' kategorisindeki ürünler: güncel fiyatlar, stok bilgisi ve hızlı teslimat.',
            'canonical'       => request_base_url() . '/kategori/' . rawurlencode($slug),
            'result'          => $result,
            'category'        => $category,
            'breadcrumb'      => $breadcrumb,
            'q'               => trim((string) ($filters['q'] ?? '')),
            'filters'         => $filters,
            'mode'            => 'category',
        ], 'layouts/store');
    }

    public function search(Request $request): Response
    {
        $filters = $this->filtersFromQuery($request);
        $page = $this->pageFromQuery($request);
        $q = trim((string) ($filters['q'] ?? ''));

        $result = $this->products->search($filters, self::PER_PAGE, $page);

        return View::render('store/catalog/listing', [
            'title'           => $q !== '' ? 'Arama: ' . $q : 'Arama',
            'metaDescription' => $q !== ''
                ? '"' . $q . '" için ürün arama sonuçları.'
                : 'Ürün arama sonuçları.',
            'canonical'       => request_base_url() . '/arama' . ($q !== '' ? '?q=' . rawurlencode($q) : ''),
            'result'          => $result,
            'category'        => null,
            'breadcrumb'      => [],
            'q'               => $q,
            'filters'         => $filters,
            'mode'            => 'search',
        ], 'layouts/store');
    }

    /* -----------------------------------------------------------------
     * İç yardımcılar
     * ----------------------------------------------------------------- */

    /**
     * GET parametrelerinden listing filtreleri (beyaz liste + temizlik).
     * Service, is_numeric/boş kontrollerini kendisi de yapar; burada view'a
     * geri basılacak değerlerin temiz olması garanti edilir.
     *
     * @return array{q:string, min_price:string, max_price:string, in_stock:string, brand:string, sort:string}
     */
    private function filtersFromQuery(Request $request): array
    {
        $sort = (string) $request->input('sort', 'newest');

        return [
            'q'         => mb_substr(trim((string) $request->input('q', '')), 0, 120),
            'min_price' => $this->numericOrNull((string) $request->input('min_price', '')),
            'max_price' => $this->numericOrNull((string) $request->input('max_price', '')),
            'in_stock'  => $request->input('in_stock') ? '1' : '',
            'brand'     => mb_substr(trim((string) $request->input('brand', '')), 0, 80),
            'sort'      => in_array($sort, self::SORTS, true) ? $sort : 'newest',
        ];
    }

    private function pageFromQuery(Request $request): int
    {
        $page = (int) $request->input('page', 1);
        return max(1, min(1000, $page));
    }

    /** Sayıya çevrilemeyen fiyat girdisi boşa indirilir (URL kirletmemek için). */
    private function numericOrNull(string $value): string
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        return $value !== '' && is_numeric($value) ? $value : '';
    }
}
