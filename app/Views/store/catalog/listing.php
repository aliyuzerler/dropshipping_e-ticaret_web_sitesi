<?php
/**
 * Katalog listeleme — kategori ve arama için ortak şablon.
 * Tam yol: app/Views/store/catalog/listing.php
 *
 * Beklenen: $result (search sonucu), $category (?array), $breadcrumb (list),
 *           $q (string), $filters (array), $mode ('category'|'search')
 */
$result = is_array($result ?? null) ? $result : ['rows' => [], 'total' => 0, 'page' => 1, 'perPage' => 12, 'lastPage' => 1];
$category = is_array($category ?? null) ? $category : null;
$breadcrumb = is_array($breadcrumb ?? null) ? $breadcrumb : [];
$q = (string) ($q ?? '');
$filters = is_array($filters ?? null) ? $filters : [];
/** @var string $mode */
$mode = ($mode ?? 'search') === 'category' ? 'category' : 'search';
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$page = max(1, (int) ($result['page'] ?? 1));
$lastPage = max(1, (int) ($result['lastPage'] ?? 1));
$total = (int) ($result['total'] ?? 0);

$actionPath = $mode === 'category' && $category !== null
    ? '/kategori/' . rawurlencode((string) ($category['slug'] ?? ''))
    : '/arama';
$clearPath = $actionPath; // "Temizle" → sorgusuz mevcut adres

$sortOptions = [
    'newest'     => 'En Yeni',
    'price_asc'  => 'Fiyat: Artan',
    'price_desc' => 'Fiyat: Azalan',
    'name_asc'   => 'İsim (A-Z)',
];

// Sayfalama mevcut filtreleri korur (page parametresi partial içinde eklenir)
$paginationQuery = [];
foreach (['q', 'min_price', 'max_price', 'in_stock', 'brand', 'sort'] as $key) {
    if (isset($filters[$key]) && $filters[$key] !== '' && $filters[$key] !== null) {
        $paginationQuery[$key] = $filters[$key];
    }
}
?>
<div class="container py-4">

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <?php if ($mode === 'category'): ?>
                <?php foreach ($breadcrumb as $crumb): ?>
                    <li class="breadcrumb-item">
                        <a href="/kategori/<?= e((string) ($crumb['slug'] ?? '')) ?>"><?= e((string) ($crumb['name'] ?? '')) ?></a>
                    </li>
                <?php endforeach; ?>
                <li class="breadcrumb-item active" aria-current="page"><?= e((string) ($category['name'] ?? '')) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item active" aria-current="page">Arama</li>
            <?php endif; ?>
        </ol>
    </nav>

    <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
        <h1 class="section-title mb-0">
            <?= $mode === 'category' ? e((string) ($category['name'] ?? '')) : 'Arama Sonuçları' ?>
        </h1>
        <span class="text-muted small"><strong><?= $total ?></strong> ürün bulundu</span>
    </div>
    <?php if ($mode === 'search' && $q !== ''): ?>
        <p class="text-muted">“<strong><?= e($q) ?></strong>” için arama sonuçları.</p>
    <?php endif; ?>

    <!-- Filtre çubuğu -->
    <form class="filter-bar p-3 my-3" method="get" action="<?= e($actionPath) ?>" role="search" aria-label="Ürün filtreleme">
        <div class="row g-2 align-items-end">
            <?php if ($mode === 'search'): ?>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="fQ">Arama</label>
                    <input type="search" class="form-control" id="fQ" name="q" value="<?= e($q) ?>"
                           placeholder="Ürün adı, marka veya barkod…" maxlength="120">
                </div>
            <?php endif; ?>
            <div class="col-6 col-md-2">
                <label class="form-label" for="fMin">Min Fiyat (₺)</label>
                <input type="number" class="form-control" id="fMin" name="min_price"
                       value="<?= e((string) ($filters['min_price'] ?? '')) ?>" min="0" step="0.01" inputmode="decimal">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" for="fMax">Maks Fiyat (₺)</label>
                <input type="number" class="form-control" id="fMax" name="max_price"
                       value="<?= e((string) ($filters['max_price'] ?? '')) ?>" min="0" step="0.01" inputmode="decimal">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label" for="fSort">Sıralama</label>
                <select class="form-select" id="fSort" name="sort">
                    <?php foreach ($sortOptions as $sortValue => $sortLabel): ?>
                        <option value="<?= e($sortValue) ?>" <?= (string) ($filters['sort'] ?? 'newest') === $sortValue ? 'selected' : '' ?>>
                            <?= e($sortLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="fInStock" name="in_stock" value="1"
                        <?= !empty($filters['in_stock']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="fInStock">Sadece stokta olanlar</label>
                </div>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-brand">Filtreleri Uygula</button>
                <a href="<?= e($clearPath) ?>" class="btn btn-outline-secondary">Temizle</a>
            </div>
        </div>
        <?php if (($filters['brand'] ?? '') !== '' && ($filters['brand'] ?? '') !== null): ?>
            <!-- Marka filtresi URL'den gelmişse form gönderiminde korunur -->
            <input type="hidden" name="brand" value="<?= e((string) $filters['brand']) ?>">
        <?php endif; ?>
    </form>

    <!-- Ürün grid'i -->
    <?php if ($rows === []): ?>
        <div class="text-center py-5">
            <div class="display-4" aria-hidden="true">🔍</div>
            <p class="lead mt-2">
                <?php if ($mode === 'search'): ?>
                    <?= $q !== '' ? '“' . e($q) . '” için sonuç bulunamadı.' : 'Aramanızla eşleşen ürün bulunamadı.' ?>
                <?php else: ?>
                    Bu kategoride henüz ürün yok.
                <?php endif; ?>
            </p>
            <p class="text-muted small">Filtreleri gevşetmeyi veya farklı bir arama denemeyi öneririz.</p>
            <a href="/" class="btn btn-brand">Tüm Ürünlere Dön</a>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rows as $p): ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <?= \App\Core\View::partial('store/partials/_product_card', ['p' => $p]) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= \App\Core\View::partial('store/partials/_pagination', [
        'page'        => $page,
        'lastPage'    => $lastPage,
        'baseUrl'     => $actionPath,
        'queryParams' => $paginationQuery,
    ]) ?>

</div>
