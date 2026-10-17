<?php
/**
 * Ana sayfa — hero banner + kategori kartları + öne çıkan ürünler.
 * Tam yol: app/Views/store/home/index.php
 *
 * Beklenen: $featured (StoreProductService::featured satırları), $categories (categoriesTree)
 */
/** @var list<array<string,mixed>> $featured */
/** @var list<array<string,mixed>> $categories */
$featured = $featured ?? [];
$categories = $categories ?? [];
$categoryIcons = ['✏️', '📚', '🎨', '📐', '🗂️', '✂️', '🖨️', '🧮', '📎', '🖊️', '🖍️', '📁'];
$freeShippingThreshold = 0.0;
try {
    $freeShippingThreshold = (new \App\Services\Store\SettingsService())->freeShippingThreshold();
} catch (\Throwable) {
    $freeShippingThreshold = 0.0;
}
?>
<div class="container py-4">

    <!-- Hero banner -->
    <section class="hero-banner p-4 p-md-5 mb-5" aria-labelledby="heroTitle">
        <div class="row align-items-center g-3">
            <div class="col-12 col-md-8">
                <h1 id="heroTitle" class="fw-bold mb-2">Mağazamıza Hoş Geldiniz 👋</h1>
                <p class="fs-5 mb-3">
                    Kırtasiye ve ofis ürünlerinde güvenli alışveriş; binlerce ürün, güncel fiyatlar, hızlı teslimat.
                    <?php if ($freeShippingThreshold > 0): ?>
                        <strong><?= e(money($freeShippingThreshold)) ?></strong> ve üzeri siparişlerde <strong>ÜCRETSİZ KARGO!</strong> 🚚
                    <?php else: ?>
                        Belirli tutarın üzerindeki siparişlerde <strong>ÜCRETSİZ KARGO!</strong> 🚚
                    <?php endif; ?>
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="#featured" class="btn btn-light fw-semibold">Öne Çıkan Ürünler</a>
                    <a href="/arama" class="btn btn-outline-light">Tüm Ürünleri Keşfet</a>
                </div>
            </div>
            <div class="col-12 col-md-4 text-center d-none d-md-block" aria-hidden="true">
                <span style="font-size:6rem">🛒</span>
            </div>
        </div>
    </section>

    <!-- Kategori kartları -->
    <?php if ($categories !== []): ?>
        <section class="mb-5" aria-labelledby="homeCategories">
            <h2 id="homeCategories" class="section-title mb-3">Kategoriler</h2>
            <div class="row g-3">
                <?php foreach ($categories as $i => $cat): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <a class="card product-card h-100 text-decoration-none p-3 d-flex flex-row align-items-center gap-3"
                           href="/kategori/<?= e((string) ($cat['slug'] ?? '')) ?>">
                            <span class="fs-2" aria-hidden="true"><?= e($categoryIcons[$i % count($categoryIcons)]) ?></span>
                            <span>
                                <span class="fw-bold d-block text-dark"><?= e((string) ($cat['name'] ?? '')) ?></span>
                                <span class="small text-muted">
                                    <?= count((array) ($cat['children'] ?? [])) > 0
                                        ? count((array) $cat['children']) . ' alt kategori' : 'Ürünleri gör →' ?>
                                </span>
                            </span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Öne çıkan ürünler -->
    <section id="featured" class="mb-4" aria-labelledby="homeFeatured">
        <h2 id="homeFeatured" class="section-title mb-3">Öne Çıkan Ürünler</h2>
        <?php if ($featured === []): ?>
            <div class="text-center py-5 bg-light rounded">
                <div class="display-4" aria-hidden="true">🛍️</div>
                <p class="lead mt-2 mb-0">Şu anda öne çıkan ürün bulunmuyor.</p>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($featured as $p): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <?= \App\Core\View::partial('store/partials/_product_card', ['p' => $p]) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</div>
