<?php
/**
 * Mağaza üst bilgisi: topbar + navbar + kategori menüsü + arama + sepet.
 * Tam yol: app/Views/store/partials/_header.php
 *
 * Beklenen: $storeName, $navCategories, $cartCount, $isLoggedIn, $customerName, $settings
 */
?>
<header class="store-header">
    <!-- Üst şerit -->
    <div class="topbar text-white">
        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-1 py-1">
            <span class="small">
                <?= e((float) $settings->freeShippingThreshold() > 0 ? money($settings->freeShippingThreshold()) . ' ve üzeri ücretsiz kargo' : '') ?>
            </span>
            <span class="small d-none d-md-inline">
                <a class="topbar-link" href="/iade-ve-teslimat">İade Koşulları</a>
                <span aria-hidden="true">·</span>
                <a class="topbar-link" href="/iletisim">İletişim</a>
            </span>
        </div>
    </div>

    <!-- Ana navbar -->
    <nav class="navbar navbar-expand-lg bg-white shadow-sm store-navbar" aria-label="Ana menü">
        <div class="container">
            <a class="navbar-brand fw-bold brand-mark" href="/" aria-label="<?= e($storeName) ?> ana sayfa">
                <span class="brand-icon" aria-hidden="true">🛒</span> <?= e($storeName) ?>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#storeNav"
                    aria-controls="storeNav" aria-expanded="false" aria-label="Menüyü aç/kapat">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="storeNav">
                <!-- Arama -->
                <form class="d-flex mx-lg-3 my-2 my-lg-0 flex-grow-1" role="search" action="/arama" method="get">
                    <label class="visually-hidden" for="storeSearch">Ürün ara</label>
                    <input class="form-control search-input" id="storeSearch" type="search" name="q"
                           value="<?= e($_GET['q'] ?? '') ?>" placeholder="Ürün, marka veya barkod ara…"
                           maxlength="120" autocomplete="off">
                    <button class="btn btn-brand ms-2 px-3" type="submit" aria-label="Ara">Ara</button>
                </form>

                <!-- Hesap + sepet -->
                <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                    <?php if ($isLoggedIn): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="/hesabim" id="accountMenu" role="button"
                               data-bs-toggle="dropdown" aria-expanded="false">
                                👤 <?= e($customerName) ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="accountMenu">
                                <li><a class="dropdown-item" href="/hesabim">Hesabım</a></li>
                                <li><a class="dropdown-item" href="/hesabim/siparisler">Siparişlerim</a></li>
                                <li><a class="dropdown-item" href="/hesabim/adresler">Adreslerim</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" action="/cikis">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="dropdown-item text-danger">Çıkış Yap</button>
                                    </form>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="/giris">Giriş Yap</a></li>
                        <li class="nav-item"><a class="nav-link" href="/kayit">Üye Ol</a></li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="btn btn-brand position-relative ms-lg-2" href="/sepet" aria-label="Sepetim, <?= (int) $cartCount ?> ürün">
                            🧺 Sepetim
                            <span class="badge rounded-pill bg-warning text-dark position-absolute top-0 start-100 translate-middle cart-badge"><?= (int) $cartCount ?></span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Kategori şeridi (mobilde yatay kaydırılabilir) -->
    <nav class="category-strip border-bottom bg-light" aria-label="Kategoriler">
        <div class="container">
            <ul class="category-list d-flex flex-nowrap gap-1 py-2 mb-0 list-unstyled">
                <li><a class="category-chip <?= ($_SERVER['REQUEST_URI'] ?? '') === '/' ? 'active' : '' ?>" href="/">Tüm Ürünler</a></li>
                <?php foreach ($navCategories as $cat): ?>
                    <?php if (!empty($cat['children'])): ?>
                        <li class="nav-item dropdown category-drop">
                            <a class="category-chip dropdown-toggle" href="/kategori/<?= e($cat['slug']) ?>" id="catDrop<?= (int) $cat['id'] ?>"
                               role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= e($cat['name']) ?></a>
                            <ul class="dropdown-menu" aria-labelledby="catDrop<?= (int) $cat['id'] ?>">
                                <li><a class="dropdown-item" href="/kategori/<?= e($cat['slug']) ?>"><?= e($cat['name']) ?> (Tümü)</a></li>
                                <?php foreach ($cat['children'] as $child): ?>
                                    <li><a class="dropdown-item" href="/kategori/<?= e($child['slug']) ?>"><?= e($child['name']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li><a class="category-chip" href="/kategori/<?= e($cat['slug']) ?>"><?= e($cat['name']) ?></a></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    </nav>
</header>
