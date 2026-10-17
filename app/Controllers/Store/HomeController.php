<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Store\StoreProductService;

/**
 * Ana sayfa — hero banner + kategori kartları + öne çıkan ürünler.
 *
 * Tam yol: app/Controllers/Store/HomeController.php
 */
final class HomeController
{
    private StoreProductService $products;

    public function __construct(?StoreProductService $products = null)
    {
        $this->products = $products ?? new StoreProductService();
    }

    public function index(Request $request): Response
    {
        $featured = $this->products->featured(8);
        $categories = $this->products->categoriesTree();

        return View::render('store/home/index', [
            'title'           => 'Ana Sayfa',
            'metaDescription' => 'Kırtasiye ve ofis ürünlerinde güvenli alışveriş; binlerce ürün, uygun fiyat ve belirli tutarın üzerindeki siparişlerde ücretsiz kargo.',
            'canonical'       => request_base_url() . '/',
            'featured'        => $featured,
            'categories'      => $categories,
        ], 'layouts/store');
    }
}
