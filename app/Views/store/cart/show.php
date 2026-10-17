<?php
/**
 * Sepet ekranı. Tam yol: app/Views/store/cart/show.php
 * Beklenen: $summary (CartService::summary())
 */
$items = $summary['items'];
$coupon = $summary['coupon'];
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item active" aria-current="page">Sepetim</li>
        </ol>
    </nav>

    <h1 class="section-title mb-4">Sepetim (<?= (int) $summary['count'] ?> ürün)</h1>

    <?php if ($items === []): ?>
        <div class="text-center py-5">
            <div class="display-4" aria-hidden="true">🧺</div>
            <p class="lead mt-2">Sepetiniz şu an boş.</p>
            <a href="/" class="btn btn-brand">Alışverişe Başla</a>
        </div>
    <?php else: ?>
        <?php if ($summary['has_unavailable']): ?>
            <div class="alert alert-warning" role="alert">
                ⚠️ Sepetinizde satın alınamayan kalemler var. Siparişi tamamlayabilmek için bu kalemleri çıkarın veya adetleri güncelleyin.
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-lg-8">
                <div class="list-group">
                    <?php foreach ($items as $item): ?>
                        <div class="list-group-item <?= $item['available'] ? '' : 'bg-light' ?>">
                            <div class="d-flex gap-3">
                                <a href="/urun/<?= e($item['slug']) ?>" class="flex-shrink-0" style="width:84px" aria-hidden="true" tabindex="-1">
                                    <?php if (!empty($item['image_path'])): ?>
                                        <img src="<?= e($item['image_path']) ?>" alt="" width="84" height="84"
                                             class="rounded border" loading="lazy" decoding="async" style="object-fit:cover">
                                    <?php else: ?>
                                        <div class="rounded border d-flex align-items-center justify-content-center bg-secondary-subtle" style="width:84px;height:84px">📦</div>
                                    <?php endif; ?>
                                </a>
                                <div class="flex-grow-1">
                                    <a class="fw-semibold d-block text-decoration-none text-dark" href="/urun/<?= e($item['slug']) ?>">
                                        <?= e($item['name']) ?>
                                    </a>
                                    <?php if ($item['variant_label']): ?>
                                        <div class="small text-muted"><?= e($item['variant_label']) ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted">Birim: <?= money($item['unit_price']) ?></div>

                                    <?php if ($item['available']): ?>
                                        <form method="post" action="/sepet/guncelle" class="d-flex align-items-center gap-2 mt-2">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <div class="input-group input-group-sm cart-qty">
                                                <button type="button" class="btn btn-outline-secondary" data-step="-1" data-target="#qty<?= (int) $item['id'] ?>" aria-label="Azalt">−</button>
                                                <label class="visually-hidden" for="qty<?= (int) $item['id'] ?>">Adet</label>
                                                <input type="number" class="form-control text-center" id="qty<?= (int) $item['id'] ?>"
                                                       name="quantity" value="<?= (int) $item['quantity'] ?>"
                                                       min="1" max="<?= max(1, (int) $item['stock']) ?>">
                                                <button type="button" class="btn btn-outline-secondary" data-step="1" data-target="#qty<?= (int) $item['id'] ?>" aria-label="Artır">+</button>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-outline-brand">Güncelle</button>
                                        </form>
                                    <?php else: ?>
                                        <div class="small text-danger mt-2"><?= e($item['reason'] ?? 'Satın alınamaz.') ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold <?= $item['available'] ? '' : 'text-muted text-decoration-line-through' ?>">
                                        <?= money($item['line_total']) ?>
                                    </div>
                                    <form method="post" action="/sepet/kaldir" class="mt-2">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" aria-label="<?= e($item['name']) ?> ürününü sepetten kaldır">Kaldır</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3">
                    <a href="/" class="btn btn-outline-brand">← Alışverişe Devam Et</a>
                    <form method="post" action="/sepet/temizle" onsubmit="return confirm('Sepeti tamamen boşaltmak istediğinize emin misiniz?');">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-secondary">Sepeti Boşalt</button>
                    </form>
                </div>
            </div>

            <div class="col-12 col-lg-4">
                <div class="summary-card p-3 bg-white">
                    <h2 class="h5 mb-3">Sipariş Özeti</h2>

                    <div class="summary-row"><span>Ara Toplam</span><strong><?= money($summary['subtotal']) ?></strong></div>

                    <?php if ($coupon !== null): ?>
                        <div class="summary-row text-success">
                            <span>
                                Kupon (<?= e($coupon['code']) ?>)
                                <form method="post" action="/sepet/kupon-kaldir" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-link text-danger p-0 ms-1">kaldır</button>
                                </form>
                            </span>
                            <strong>−<?= money($summary['discount_total']) ?></strong>
                        </div>
                    <?php else: ?>
                        <form method="post" action="/sepet/kupon" class="d-flex gap-1 my-2">
                            <?= csrf_field() ?>
                            <label class="visually-hidden" for="couponCode">Kupon kodu</label>
                            <input type="text" class="form-control form-control-sm" id="couponCode" name="code"
                                   placeholder="Kupon kodu" value="<?= e(old('code')) ?>" maxlength="40">
                            <button type="submit" class="btn btn-sm btn-outline-brand">Uygula</button>
                        </form>
                    <?php endif; ?>

                    <div class="summary-row">
                        <span>Kargo</span>
                        <strong><?= $summary['shipping_total'] > 0 ? money($summary['shipping_total']) : 'Ücretsiz' ?></strong>
                    </div>
                    <?php if ($summary['free_shipping_remaining'] > 0): ?>
                        <p class="small text-muted mb-2">
                            Ücretsiz kargoya kalan: <strong><?= money($summary['free_shipping_remaining']) ?></strong>
                        </p>
                    <?php endif; ?>

                    <hr>
                    <div class="summary-row summary-total"><span>Toplam</span><span><?= money($summary['grand_total']) ?></span></div>
                    <p class="small text-muted mb-3">Fiyatlar KDV dahildir.</p>

                    <a href="/odeme" class="btn btn-brand w-100 <?= $summary['has_unavailable'] ? 'disabled' : '' ?>"
                       <?= $summary['has_unavailable'] ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                        Ödemeye Geç →
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
