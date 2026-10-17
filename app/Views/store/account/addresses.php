<?php
/**
 * Adres defteri listesi. Tam yol: app/Views/store/account/addresses.php
 * Beklenen: $addresses (list<array<string,mixed>> — id, title, full_name,
 * country_code, city, district, postal_code, line1, line2, phone, is_default).
 * Silme onayı: data-confirm — layout'taki store.js bu niteliği işlemez;
 * bu dosyanın altındaki yakalama evreli (capture) script güvenli biçimde yönetir.
 */
$addresses = is_array($addresses ?? null) ? $addresses : [];
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item"><a href="/hesabim">Hesabım</a></li>
            <li class="breadcrumb-item active" aria-current="page">Adreslerim</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="section-title mb-0">Adreslerim</h1>
        <a class="btn btn-brand" href="/hesabim/adresler/yeni">+ Yeni Adres</a>
    </div>

    <?php if ($addresses === []): ?>
        <div class="alert alert-light border" role="alert">
            📍 Kayıtlı adresiniz yok. Checkout sırasında yeni adres kullanabilir veya
            <a href="/hesabim/adresler/yeni">şimdi bir adres ekleyebilirsiniz</a> — siparişleriniz daha hızlı tamamlanır.
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($addresses as $addr): ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card h-100 shadow-sm border-0 <?= (int) ($addr['is_default'] ?? 0) === 1 ? 'border-brand-subtle' : '' ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h2 class="h6 fw-bold mb-0"><?= e((string) ($addr['title'] ?? '') !== '' ? (string) $addr['title'] : 'Adres #' . (int) ($addr['id'] ?? 0)) ?></h2>
                                <?php if ((int) ($addr['is_default'] ?? 0) === 1): ?>
                                    <span class="badge text-bg-success">Varsayılan</span>
                                <?php endif; ?>
                            </div>
                            <address class="small text-muted mb-2" style="font-style:normal">
                                <strong class="text-body"><?= e((string) ($addr['full_name'] ?? '')) ?></strong><br>
                                <?= e((string) ($addr['line1'] ?? '')) ?><?= (string) ($addr['line2'] ?? '') !== '' ? '<br>' . e((string) $addr['line2']) : '' ?><br>
                                <?= e((string) ($addr['district'] ?? '')) ?>/<?= e((string) ($addr['city'] ?? '')) ?>
                                <?= (string) ($addr['postal_code'] ?? '') !== '' ? e(' ' . (string) $addr['postal_code']) : '' ?><br>
                                <?= e((string) ($addr['country_code'] ?? 'TR')) ?>
                                <?php if ((string) ($addr['phone'] ?? '') !== ''): ?><br>☎ <?= e((string) $addr['phone']) ?><?php endif; ?>
                            </address>
                        </div>
                        <div class="card-footer bg-white d-flex flex-wrap gap-2">
                            <a class="btn btn-sm btn-outline-brand" href="/hesabim/adresler/<?= (int) ($addr['id'] ?? 0) ?>/duzenle">Düzenle</a>
                            <?php if ((int) ($addr['is_default'] ?? 0) !== 1): ?>
                                <form method="post" action="/hesabim/adresler/<?= (int) ($addr['id'] ?? 0) ?>/varsayilan" class="d-inline">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Varsayılan Yap</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/hesabim/adresler/<?= (int) ($addr['id'] ?? 0) ?>/sil"
                                  class="d-inline ms-auto" data-confirm="Bu adresi silmek istediğinize emin misiniz?">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">Sil</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
/* data-confirm onayı (yakalama evresi) — store.js'e dokunmadan burada karşılanır. */
(function () {
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || typeof form.getAttribute !== 'function') { return; }
        var msg = form.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) {
            ev.preventDefault();
            ev.stopImmediatePropagation();
        }
    }, true);
})();
</script>
