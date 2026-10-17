<?php
/**
 * Ürün detay sayfası (PDP) — galeri + satın alma formu + benzer ürünler.
 * Tam yol: app/Views/store/product/show.php
 *
 * Beklenen: $product (productForStore), $related (related satırları)
 */
$product = is_array($product ?? null) ? $product : [];
$related = is_array($related ?? null) ? $related : [];
$images = is_array($product['images'] ?? null) ? $product['images'] : [];
$variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
$isStale = !empty($product['is_stale']);
$purchasable = !empty($product['purchasable']);
$stock = (int) ($product['stock'] ?? 0);
$salePrice = ($product['sale_price'] ?? null) !== null && $product['sale_price'] !== ''
    ? (float) $product['sale_price']
    : (float) ($product['list_price'] ?? 0);
$listPrice = (float) ($product['list_price'] ?? 0);
$supplierLastSeen = ($product['supplier_last_seen'] ?? null);

/** Varyant etiketi: attributes_parsed "Anahtar: Değer" çiftlerinden okunur. */
$variantLabel = static function (array $variant): string {
    $parts = [];
    foreach ((array) ($variant['attributes_parsed'] ?? []) as $attrKey => $attrValue) {
        $value = is_scalar($attrValue)
            ? (string) $attrValue
            : (string) json_encode($attrValue, JSON_UNESCAPED_UNICODE);
        $parts[] = (is_string($attrKey) && $attrKey !== '' ? $attrKey . ': ' : '') . $value;
    }
    return $parts === []
        ? 'Varyant #' . (string) ($variant['id'] ?? '?')
        : implode(' · ', $parts);
};
?>
<div class="container py-4">

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <?php foreach ((array) ($product['breadcrumb'] ?? []) as $crumb): ?>
                <li class="breadcrumb-item">
                    <a href="/kategori/<?= e((string) ($crumb['slug'] ?? '')) ?>"><?= e((string) ($crumb['name'] ?? '')) ?></a>
                </li>
            <?php endforeach; ?>
            <li class="breadcrumb-item active" aria-current="page"><?= e((string) ($product['name'] ?? '')) ?></li>
        </ol>
    </nav>

    <div class="row g-4 mb-5">

        <!-- Sol: görsel galerisi -->
        <div class="col-12 col-lg-6">
            <?php if ($images !== []): ?>
                <img id="pdpMainImage" class="pdp-main-img" src="<?= e((string) ($images[0]['path'] ?? '')) ?>"
                     alt="<?= e((string) ($product['name'] ?? '')) ?>" width="600" height="600" decoding="async">
                <?php if (count($images) > 1): ?>
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <?php foreach ($images as $i => $img): ?>
                            <button type="button" class="btn p-0 bg-transparent border-0"
                                    aria-label="<?= e((string) ($product['name'] ?? '')) ?> görsel <?= $i + 1 ?>">
                                <img class="pdp-thumb <?= $i === 0 ? 'active' : '' ?>"
                                     src="<?= e((string) ($img['path'] ?? '')) ?>"
                                     data-src="<?= e((string) ($img['path'] ?? '')) ?>"
                                     alt="" width="64" height="64" loading="lazy" decoding="async">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="pdp-main-img d-flex align-items-center justify-content-center" style="font-size:6rem" aria-hidden="true">📦</div>
            <?php endif; ?>
        </div>

        <!-- Sağ: ürün bilgisi + satın alma -->
        <div class="col-12 col-lg-6">
            <?php if (($brand = trim((string) ($product['brand'] ?? ''))) !== ''): ?>
                <div class="product-brand mb-1"><?= e($brand) ?></div>
            <?php endif; ?>

            <h1 class="h3 fw-bold mb-2"><?= e((string) ($product['name'] ?? '')) ?></h1>
            <p class="small text-muted mb-3">Ürün Kodu (SKU): <span class="fw-semibold text-dark"><?= e((string) ($product['sku'] ?? '')) ?></span></p>

            <div class="mb-1">
                <span class="price-now fs-4"><?= e(money($salePrice)) ?></span>
                <?php if ($listPrice > $salePrice): ?>
                    <span class="price-old fs-6"><?= e(money($listPrice)) ?></span>
                <?php endif; ?>
            </div>
            <p class="small text-muted mb-3">Fiyatlara KDV dahildir.</p>

            <?php if ($stock > 0): ?>
                <p class="text-success fw-semibold mb-2">✔ Stokta — <?= $stock ?> adet</p>
            <?php else: ?>
                <p class="mb-2"><span class="badge badge-oos">Stokta Yok</span></p>
            <?php endif; ?>

            <?php if ($isStale): ?>
                <div class="stale-banner p-3 mb-3" role="alert">
                    ⚠️ <strong>Tedarikçi stok bilgisi güncel değil — satın alma kapalı.</strong>
                    <div class="small mt-1">En güncel fiyat/stok bilgisi için lütfen daha sonra tekrar kontrol edin.</div>
                </div>
            <?php elseif ($supplierLastSeen !== null && $supplierLastSeen !== ''): ?>
                <p class="small text-muted mb-3">
                    🔄 Stok/fiyat bilgisi tedarikçiden son olarak
                    <?= e(substr((string) $supplierLastSeen, 0, 16)) ?> tarihinde alındı.
                </p>
            <?php endif; ?>

            <!-- Satın alma formu -->
            <?php if ($variants !== []): ?>
                <form method="post" action="/sepet/ekle" class="border rounded p-3 mb-4 bg-white" data-add-to-cart>
                    <?= csrf_field() ?>
                    <input type="hidden" name="product_id" value="<?= (int) ($product['id'] ?? 0) ?>">

                    <fieldset class="mb-3">
                        <legend class="fs-6 fw-bold mb-2">Varyant Seçin</legend>
                        <?php foreach ($variants as $vi => $variant):
                            $variantStock = (int) ($variant['stock'] ?? 0);
                            $variantOut = $variantStock <= 0;
                            ?>
                            <div class="form-check border rounded p-2 mb-1 <?= $variantOut ? 'opacity-75' : '' ?>">
                                <input class="form-check-input" type="radio" name="variant_id"
                                       id="variant<?= (int) ($variant['id'] ?? $vi) ?>"
                                       value="<?= (int) ($variant['id'] ?? 0) ?>"
                                    <?= ($vi === 0 && !$variantOut) ? 'checked' : '' ?>
                                    <?= $variantOut ? 'disabled' : '' ?>>
                                <label class="form-check-label" for="variant<?= (int) ($variant['id'] ?? $vi) ?>">
                                    <strong><?= e($variantLabel($variant)) ?></strong>
                                    — <span class="fw-semibold"><?= e(money($variant['effective_price'] ?? 0)) ?></span>
                                    <span class="small text-muted">(<?= $variantOut ? 'Stokta yok' : $variantStock . ' adet' ?>)</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>

                    <div class="d-flex align-items-center gap-2">
                        <label class="form-label mb-0 text-nowrap" for="pdpQty">Adet</label>
                        <input type="number" class="form-control qty-input" id="pdpQty" name="quantity"
                               value="1" min="1" max="<?= max(1, $stock) ?>">
                        <button type="submit" class="btn btn-brand flex-grow-1"
                            <?= $purchasable ? '' : 'disabled aria-disabled="true"' ?>>🧺 Sepete Ekle</button>
                    </div>
                </form>
            <?php else: ?>
                <form method="post" action="/sepet/ekle" class="d-flex align-items-center gap-2 mb-4" data-add-to-cart>
                    <?= csrf_field() ?>
                    <input type="hidden" name="product_id" value="<?= (int) ($product['id'] ?? 0) ?>">
                    <label class="form-label mb-0 text-nowrap" for="pdpQty">Adet</label>
                    <input type="number" class="form-control qty-input" id="pdpQty" name="quantity"
                           value="1" min="1" max="<?= max(1, $stock) ?>">
                    <button type="submit" class="btn btn-brand flex-grow-1"
                        <?= $purchasable ? '' : 'disabled aria-disabled="true"' ?>>🧺 Sepete Ekle</button>
                </form>
            <?php endif; ?>

            <p class="small text-muted">
                🚚 Kargo bilgisi ve iade koşulları için
                <a href="/iade-ve-teslimat">İade ve Teslimat</a> sayfasına bakabilirsiniz.
            </p>
        </div>
    </div>

    <!-- Açıklama -->
    <section class="mb-5" aria-labelledby="pdpDescription">
        <h2 id="pdpDescription" class="section-title mb-3">Ürün Açıklaması</h2>
        <div class="bg-white border rounded p-3 p-md-4">
            <?= nl2br(e($product['description'] ?? '')) ?>
        </div>
    </section>

    <!-- Benzer ürünler -->
    <?php if ($related !== []): ?>
        <section class="mb-3" aria-labelledby="pdpRelated">
            <h2 id="pdpRelated" class="section-title mb-3">Benzer Ürünler</h2>
            <div class="row g-3">
                <?php foreach ($related as $rp): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <?= \App\Core\View::partial('store/partials/_product_card', ['p' => $rp]) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

</div>
