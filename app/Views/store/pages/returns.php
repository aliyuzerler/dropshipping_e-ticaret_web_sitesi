<?php
/**
 * İade ve Teslimat (TASLAK hukuki metin).
 * Tam yol: app/Views/store/pages/returns.php
 *
 * Beklenen: $title, $contentHtml (SettingsService::legal çıktısı — güvenilir
 * kaynaktan gelen HTML; RAW basılır, e() UYGULANMAZ).
 */
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e($title ?? 'İade ve Teslimat') ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h1 class="section-title mb-0"><?= e($title ?? '') ?></h1>
        <span class="badge text-bg-warning">TASLAK</span>
    </div>

    <div class="alert alert-warning small py-2" role="note">
        ⚠️ Bu sayfadaki metin <strong>taslak</strong> niteliğindedir; yayına almadan önce
        hukuki danışmanınıza kontrol ettirin. Metni <strong>Admin → Ayarlar</strong> ekranından güncelleyebilirsiniz.
    </div>

    <article class="legal-content bg-white border rounded p-3 p-md-4">
        <?= $contentHtml ?>
    </article>
</div>
