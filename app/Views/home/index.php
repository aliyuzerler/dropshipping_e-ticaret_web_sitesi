<?php
/**
 * Ana sayfa — proje durum kartları.
 * Tam yol: app/Views/home/index.php
 *
 * Değişkenler: $title, $stats (suppliers, products, import_logs), $env
 */
?>
<div class="p-4 p-md-5 mb-4 rounded-3 bg-body-tertiary border">
    <div class="container-fluid py-3">
        <h1 class="display-6 fw-bold">XML E-Ticaret Altyapısı</h1>
        <p class="col-md-9 fs-5 text-body-secondary">
            Bağımsız, modüler PHP 8.2+ e-ticaret projesi — Aşama 1 tamamlandı: mimari, proje iskeleti,
            23 migration, güvenlik altyapısı (CSRF, SSRF guard, XXE koruması), health-check ve cron altyapısı.
        </p>
        <div class="d-flex flex-wrap gap-2">
            <a href="/health" class="btn btn-success btn-sm" target="_blank" rel="noopener">Health-Control Endpoint</a>
            <a href="/admin/login" class="btn btn-outline-secondary btn-sm">Yönetici Girişi</a>
            <a href="/docs/architecture.md" class="btn btn-outline-secondary btn-sm" onclick="alert('docs/ klasöründe: architecture.md, database-design.md, security.md, development-roadmap.md');return false;">Dokümantasyon</a>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h2 class="h5 card-title">Tedarikçiler</h2>
                <p class="display-6 fw-semibold mb-0"><?= e($stats['suppliers'] ?? 0) ?></p>
                <p class="text-body-secondary small mb-0">suppliers tablosu</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h2 class="h5 card-title">Ürünler</h2>
                <p class="display-6 fw-semibold mb-0"><?= e($stats['products'] ?? 0) ?></p>
                <p class="text-body-secondary small mb-0">products tablosu</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h2 class="h5 card-title">XML İthalat Kayıtları</h2>
                <p class="display-6 fw-semibold mb-0"><?= e($stats['import_logs'] ?? 0) ?></p>
                <p class="text-body-secondary small mb-0">xml_import_logs tablosu</p>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h2 class="h5 card-title">Aşama 1 Kapsamı</h2>
                <ul class="mb-0">
                    <li>Mimari dokümanlar: <code>docs/architecture.md</code>, <code>docs/database-design.md</code>, <code>docs/security.md</code>, <code>docs/development-roadmap.md</code></li>
                    <li>Front controller + Router + Middleware pipeline</li>
                    <li>23 migration (FK, indeks, DECIMAL para, snapshot kolonları)</li>
                    <li>SSRF guard + IP pinning + XXE korumalı XMLReader parser</li>
                    <li>CSRF, güvenli oturum, admin auth (kilitlemeli)</li>
                    <li>Health-check, cron altyapısı, test runner</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100 shadow-sm">
            <div class="card-body">
                <h2 class="h5 card-title">Sıradaki Fazlar</h2>
                <ol class="mb-0">
                    <li><strong>Faz 2:</strong> XML import motoru tam devrede (alan eşleme UI)</li>
                    <li><strong>Faz 3:</strong> Katalog &amp; fiyat kuralları yönetimi</li>
                    <li><strong>Faz 4:</strong> Vitrin, sepet, iyzico Checkout Form</li>
                    <li><strong>Faz 5:</strong> Dropshipping operasyonu (supplier_orders)</li>
                </ol>
                <p class="text-body-secondary small mt-2 mb-0">Detay: <code>docs/development-roadmap.md</code></p>
            </div>
        </div>
    </div>
</div>
