<?php
/**
 * Ürün kartı partial — listing / anasayfa / benzer ürünler grid'lerinde kullanılır.
 * Tam yol: app/Views/store/partials/_product_card.php
 *
 * Beklenen: $p (StoreProductService satırı:
 *           id,name,slug,sku,brand,stock,sale_price,list_price,image_path,is_stale,purchasable,…)
 */
$p = is_array($p ?? null) ? $p : [];
$slug = (string) ($p['slug'] ?? '');
$name = (string) ($p['name'] ?? '');
$stock = (int) ($p['stock'] ?? 0);
$isStale = !empty($p['is_stale']);
$purchasable = !empty($p['purchasable']);
$salePrice = ($p['sale_price'] ?? null) !== null && $p['sale_price'] !== ''
    ? (float) $p['sale_price']
    : (float) ($p['list_price'] ?? 0);
$listPrice = (float) ($p['list_price'] ?? 0);
?>
<div class="card product-card">
    <a href="/urun/<?= e($slug) ?>" class="card-img-wrap position-relative text-decoration-none">
        <?php if (!empty($p['image_path'])): ?>
            <img src="<?= e((string) $p['image_path']) ?>" alt="<?= e($name) ?>" width="600" height="600" loading="lazy" decoding="async">
        <?php else: ?>
            <span style="font-size:2.6rem" aria-hidden="true">📦</span>
        <?php endif; ?>
        <span class="position-absolute top-0 start-0 p-2 d-flex flex-column align-items-start gap-1">
            <?php if ($stock <= 0): ?>
                <span class="badge badge-oos">Stokta Yok</span>
            <?php endif; ?>
            <?php if ($isStale): ?>
                <span class="badge badge-stale">Bilgi Eskimiş</span>
            <?php endif; ?>
        </span>
    </a>
    <div class="card-body d-flex flex-column p-2 p-md-3">
        <?php if (($brand = trim((string) ($p['brand'] ?? ''))) !== ''): ?>
            <div class="product-brand mb-1"><?= e($brand) ?></div>
        <?php endif; ?>
        <h3 class="product-title mb-2 fs-6">
            <a href="/urun/<?= e($slug) ?>"><?= e($name) ?></a>
        </h3>
        <div class="mt-auto">
            <div class="mb-2">
                <span class="price-now"><?= e(money($salePrice)) ?></span>
                <?php if ($listPrice > $salePrice): ?>
                    <span class="price-old"><?= e(money($listPrice)) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($purchasable): ?>
                <form method="post" action="/sepet/ekle" data-add-to-cart>
                    <?= csrf_field() ?>
                    <input type="hidden" name="product_id" value="<?= (int) ($p['id'] ?? 0) ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit" class="btn btn-brand btn-sm w-100" aria-label="<?= e($name) ?> ürününü sepete ekle">
                        🧺 Sepete Ekle
                    </button>
                </form>
            <?php else: ?>
                <button type="button" class="btn btn-brand btn-sm w-100" disabled aria-disabled="true">
                    <?= $stock <= 0 ? 'Stokta Yok' : 'Şu An Satın Alınamaz' ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
