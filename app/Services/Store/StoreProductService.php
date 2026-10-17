<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Models\Product;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductImageRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;
use PDO;

/**
 * Vitrin ürün sorguları — ürünler YALNIZCA veritabanından gelir.
 *  - Yalnız status='active' ve silinmemiş ürünler listelenir.
 *  - Bayat tedarikçi stok bilgisi kuralı: product_supplier_data.last_seen_at
 *    settings(stale_stock_hours) değerinden eskiyse ürün ya GİZLENİR (hide)
 *    ya da satın almaya KAPATILIR (disable). Tedarikçi kaydı olmayan
 *    (manuel) ürünler bayat sayılmaz.
 *
 * Tam yol: app/Services/Store/StoreProductService.php
 */
final class StoreProductService
{
    public function __construct(
        private ?ProductRepository $products = null,
        private ?CategoryRepository $categories = null,
        private ?ProductImageRepository $images = null,
        private ?ProductVariantRepository $variants = null,
        private ?SettingsService $settings = null,
    ) {
        $this->products ??= new ProductRepository();
        $this->categories ??= new CategoryRepository();
        $this->images ??= new ProductImageRepository();
        $this->variants ??= new ProductVariantRepository();
        $this->settings ??= new SettingsService();
    }

    /* ---------------------------------------------------------------------
     * Liste sorgusu (arama / kategori / filtre / sıralama / sayfalama)
     * ------------------------------------------------------------------- */

    /**
     * $f: q, category (slug), min_price, max_price, in_stock, brand, sort, page
     *
     * @return array{rows: list<array<string,mixed>>, total: int, page: int, perPage: int, lastPage: int, filters: array<string,mixed>}
     */
    public function search(array $f = [], int $perPage = 12, int $page = 1): array
    {
        $perPage = max(1, min(48, $perPage));
        $page = max(1, $page);

        [$where, $params] = $this->buildWhere($f);

        $baseFrom = 'FROM products p LEFT JOIN categories c ON c.id = p.category_id';

        $countStmt = $this->pdo()->prepare('SELECT COUNT(*) ' . $baseFrom . $where);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT p.id, p.name, p.slug, p.sku, p.brand, p.barcode, p.stock, p.stock_status,
                       p.sale_price, p.list_price, p.tax_rate, p.currency, p.created_at,
                       c.name AS category_name, c.slug AS category_slug,
                       (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id
                        ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path '
            . $baseFrom . $where
            . ' ' . $this->orderBy((string) ($f['sort'] ?? 'newest'))
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        // Bayat stok işaretleme (yalnız sayfadaki satırlar için tek sorgu)
        $staleIds = $this->staleProductIds(array_map(static fn ($r) => (int) $r['id'], $rows));
        foreach ($rows as &$row) {
            $row['is_stale'] = in_array((int) $row['id'], $staleIds, true);
            $row['purchasable'] = (int) $row['stock'] > 0 && !$row['is_stale'];
        }
        unset($row);

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'perPage'  => $perPage,
            'lastPage' => $lastPage,
            'filters'  => $f,
        ];
    }

    /** Anasayfa öne çıkanlar (is_featured önceliği, sonra en yeni). */
    public function featured(int $limit = 8): array
    {
        $limit = max(1, min(24, $limit));
        [$where, $params] = $this->buildWhere([]);
        $sql = 'SELECT p.id, p.name, p.slug, p.sku, p.brand, p.stock, p.stock_status, p.sale_price, p.list_price, p.tax_rate, p.currency, p.created_at,
                       c.name AS category_name, c.slug AS category_slug,
                       (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id
                        ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
                FROM products p LEFT JOIN categories c ON c.id = p.category_id '
            . $where . ' ORDER BY p.is_featured DESC, p.created_at DESC LIMIT ' . $limit;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $this->flag($rows);
    }

    /** Benzer ürünler: aynı kategoride, aktif, kendisi hariç. */
    public function related(int $productId, ?int $categoryId, int $limit = 4): array
    {
        $limit = max(1, min(8, $limit));
        [$where, $params] = $this->buildWhere([]);
        $where .= $categoryId !== null ? ' AND p.category_id = ?' : ' AND p.id != ?';
        $params[] = $categoryId !== null ? $categoryId : $productId;
        $sql = 'SELECT p.id, p.name, p.slug, p.sku, p.brand, p.stock, p.stock_status, p.sale_price, p.list_price, p.tax_rate, p.currency, p.created_at,
                       c.name AS category_name, c.slug AS category_slug,
                       (SELECT pi.path FROM product_images pi WHERE pi.product_id = p.id
                        ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
                FROM products p LEFT JOIN categories c ON c.id = p.category_id '
            . $where . ' ORDER BY p.created_at DESC LIMIT ' . $limit;
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $this->flag($rows);
    }

    /* ---------------------------------------------------------------------
     * Ürün detayı
     * ------------------------------------------------------------------- */

    /**
     * Vitrin ürün detayı. Gizli ürünlerde (hide + bayat / pasif / silinmiş) NULL.
     *
     * @return array<string,mixed>|null
     */
    public function productForStore(string $slug): ?array
    {
        $product = $this->products->findBySlug($slug);
        if ($product === null || $product['status'] !== Product::STATUS_ACTIVE) {
            return null;
        }

        $productId = (int) $product['id'];
        $isStale = $this->staleProductIds([$productId]) !== [];

        if ($isStale && $this->settings->staleStockAction() === 'hide') {
            return null;
        }

        $product['is_stale'] = $isStale;
        $product['stale_hours'] = $this->settings->staleStockHours();
        $product['purchasable'] = (int) $product['stock'] > 0 && !$isStale;
        $product['images'] = $this->images->forProduct($productId);
        $product['variants'] = $this->decorateVariants($this->variants->forProduct($productId), $product);
        $product['category'] = $product['category_id'] !== null
            ? $this->categories->find((int) $product['category_id'])
            : null;
        $product['breadcrumb'] = $this->breadcrumb((array) ($product['category'] ?? []));
        $product['supplier_last_seen'] = $this->supplierLastSeen($productId);

        return $product;
    }

    /** @return list<array<string,mixed>> */
    private function decorateVariants(array $variants, array $product): array
    {
        foreach ($variants as &$v) {
            $v['effective_price'] = ProductVariantRepository::effectivePrice(
                $v['price'] ?? null,
                $product['sale_price'] ?? null
            );
            $v['attributes_parsed'] = $this->decodeAttributes($v['attributes'] ?? null);
        }
        unset($v);
        return $variants;
    }

    private function decodeAttributes(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /* ---------------------------------------------------------------------
     * Kategori yardımcıları
     * ------------------------------------------------------------------- */

    /** @return array<string,mixed>|null */
    public function categoryBySlug(string $slug): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM categories WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Kategori + tüm alt kategorilerinin id'leri (recursive, PHP tarafında). */
    public function descendantIds(int $categoryId): array
    {
        $map = $this->categories->parentMap();
        $ids = [$categoryId];
        $queue = [$categoryId];
        while ($queue !== []) {
            $current = array_pop($queue);
            foreach ($map as $id => $parentId) {
                if ($parentId === $current && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $queue[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * Navbar için kategori ağacı (yalnız aktif).
     * @return list<array<string,mixed>> her düğüm: id,name,slug,children[]
     */
    public function categoriesTree(): array
    {
        $flat = $this->categories->allOrdered();
        $nodes = [];
        foreach ($flat as $row) {
            $row['children'] = [];
            $nodes[(int) $row['id']] = $row;
        }
        $tree = [];
        foreach ($nodes as $id => $node) {
            $parentId = $node['parent_id'] !== null ? (int) $node['parent_id'] : null;
            if ($parentId !== null && isset($nodes[$parentId])) {
                $nodes[$parentId]['children'][] = &$nodes[$id];
            } else {
                $tree[] = &$nodes[$id];
            }
        }
        unset($node);
        return array_values($tree);
    }

    /** @return list<array<string,mixed>> kökten yaprağa kategori zinciri */
    public function breadcrumb(array $category): array
    {
        if ($category === []) {
            return [];
        }
        $map = $this->categories->parentMap();
        $chain = [$category];
        $parentId = $category['parent_id'] !== null ? (int) $category['parent_id'] : null;
        $guard = 0;
        while ($parentId !== null && isset($map[$parentId]) && $guard++ < 10) {
            $parent = $this->categories->find($parentId);
            if ($parent === null) {
                break;
            }
            array_unshift($chain, $parent);
            $parentId = $parent['parent_id'] !== null ? (int) $parent['parent_id'] : null;
        }
        return $chain;
    }

    /* ---------------------------------------------------------------------
     * Bayat stok (stale supplier data) kuralı
     * ------------------------------------------------------------------- */

    /**
     * Verilen ürünler arasında tedarikçi verisi bayat olanların id'leri.
     * Bayat: en az bir product_supplier_data kaydı var VE en güncel last_seen_at
     * stale_stock_hours'tan eski. Tedarikçi kaydı hiç yoksa ürün bayat DEĞİL.
     *
     * @param list<int> $productIds
     * @return list<int>
     */
    public function staleProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }
        $hours = $this->settings->staleStockHours();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT product_id FROM product_supplier_data
                WHERE product_id IN ($placeholders)
                GROUP BY product_id
                HAVING MAX(last_seen_at) IS NOT NULL
                   AND MAX(last_seen_at) < DATE_SUB(NOW(), INTERVAL " . $hours . " HOUR)";
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($ids);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return array_map(static fn ($r) => (int) $r['product_id'], $rows);
    }

    /** Ürünün tedarikçi verilerinin en güncel last_seen_at değeri. */
    public function supplierLastSeen(int $productId): ?string
    {
        $stmt = $this->pdo()->prepare(
            'SELECT MAX(last_seen_at) FROM product_supplier_data WHERE product_id = ?'
        );
        $stmt->execute([$productId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /* ---------------------------------------------------------------------
     * İç yardımcılar
     * ------------------------------------------------------------------- */

    /**
     * Ortak WHERE: aktif + silinmemiş + (hide modunda bayat olmayan) + filtreler.
     * @return array{0:string, 1:list<mixed>}
     */
    private function buildWhere(array $f): array
    {
        $where = " WHERE p.status = 'active' AND p.deleted_at IS NULL";
        $params = [];

        // hide modunda bayat ürünler listelerden tamamen çıkar
        if ($this->settings->staleStockAction() === 'hide') {
            $where .= ' AND NOT EXISTS (
                SELECT 1 FROM product_supplier_data d
                WHERE d.product_id = p.id
                HAVING MAX(d.last_seen_at) IS NOT NULL
                   AND MAX(d.last_seen_at) < DATE_SUB(NOW(), INTERVAL ' . $this->settings->staleStockHours() . ' HOUR)
            )';
        }

        // Kategori filtresi (slug → id + alt kategoriler)
        $categorySlug = trim((string) ($f['category'] ?? ''));
        if ($categorySlug !== '') {
            $category = $this->categoryBySlug($categorySlug);
            if ($category !== null) {
                $ids = $this->descendantIds((int) $category['id']);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where .= " AND p.category_id IN ($placeholders)";
                $params = [...$params, ...$ids];
                $f['_category'] = $category;
            } else {
                $where .= ' AND 1 = 0'; // bilinmeyen kategori → boş sonuç
            }
        }

        // Arama: FULLTEXT (boolean) + LIKE yedeği; barkod/brand de taranır
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $boolean = $this->fulltextBoolean($q);
            $like = '%' . mb_strtolower($q, 'UTF-8') . '%';
            $where .= ' AND (MATCH(p.name, p.description) AGAINST(? IN BOOLEAN MODE)
                        OR LOWER(p.name) LIKE ?
                        OR p.barcode LIKE ?
                        OR LOWER(COALESCE(p.brand, "")) LIKE ?)';
            $params = [...$params, $boolean, $like, '%' . $q . '%', $like];
        }

        // Fiyat aralığı (satış fiyatı yoksa liste fiyatı)
        if (isset($f['min_price']) && $f['min_price'] !== '' && is_numeric($f['min_price'])) {
            $where .= ' AND COALESCE(p.sale_price, p.list_price, 0) >= ?';
            $params[] = (float) $f['min_price'];
        }
        if (isset($f['max_price']) && $f['max_price'] !== '' && is_numeric($f['max_price'])) {
            $where .= ' AND COALESCE(p.sale_price, p.list_price, 0) <= ?';
            $params[] = (float) $f['max_price'];
        }

        // Sadece stokta olanlar
        if (!empty($f['in_stock'])) {
            $where .= ' AND p.stock > 0';
        }

        // Marka
        $brand = trim((string) ($f['brand'] ?? ''));
        if ($brand !== '') {
            $where .= ' AND p.brand = ?';
            $params[] = $brand;
        }

        return [$where, $params];
    }

    /** FULLTEXT boolean modu için güvenli sorgu dizgisi: +terim* */
    private function fulltextBoolean(string $q): string
    {
        $terms = preg_split('/[^a-z0-9çğıöşüÇĞİÖŞÜ]+/iu', mb_strtolower($q, 'UTF-8')) ?: [];
        $parts = [];
        foreach ($terms as $term) {
            $term = str_replace(['+', '-', '>', '<', '(', ')', '~', '*', '"', '@'], ' ', $term);
            $term = trim($term);
            if (mb_strlen($term, 'UTF-8') >= 2) {
                $parts[] = '+' . $term . '*';
            }
        }
        return implode(' ', $parts);
    }

    private function orderBy(string $sort): string
    {
        return match ($sort) {
            'price_asc'  => 'ORDER BY COALESCE(p.sale_price, p.list_price, 0) ASC, p.id ASC',
            'price_desc' => 'ORDER BY COALESCE(p.sale_price, p.list_price, 0) DESC, p.id ASC',
            'name_asc'   => 'ORDER BY p.name ASC',
            'stock_desc' => 'ORDER BY p.stock DESC, p.created_at DESC',
            default      => 'ORDER BY p.created_at DESC, p.id DESC',
        };
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function flag(array $rows): array
    {
        $staleIds = $this->staleProductIds(array_map(static fn ($r) => (int) $r['id'], $rows));
        foreach ($rows as &$row) {
            $row['is_stale'] = in_array((int) $row['id'], $staleIds, true);
            $row['purchasable'] = (int) $row['stock'] > 0 && !$row['is_stale'];
        }
        unset($row);
        return $rows;
    }

    private function pdo(): PDO
    {
        return \App\Core\Database::pdo();
    }
}
