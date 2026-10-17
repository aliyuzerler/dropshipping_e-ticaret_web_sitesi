<?php
/**
 * Mağaza layout'u (mobil öncelikli, Bootstrap 5.3 + jQuery).
 * Tam yol: app/Views/layouts/store.php
 *
 * Beklenen değişkenler: $content, $title, $metaDescription (ops.), $canonical (ops.),
 * $jsonLd (ops. — ham JSON-LD <script> gövdesi, çıktı ESCAPE EDİLMEZ; üretici kaçışlar)
 */
$settings = new \App\Services\Store\SettingsService();
$storeName = $settings->storeName();
$navCategories = [];
$cartCount = 0;
$customerName = null;
try {
    $navCategories = (new \App\Services\Store\StoreProductService())->categoriesTree();
} catch (\Throwable) {
    $navCategories = [];
}
try {
    $cartCount = (new \App\Services\Store\CartService())->itemCount();
} catch (\Throwable) {
    $cartCount = 0;
}
$customerId = \App\Core\Session::get('customer_id');
if (is_int($customerId) && $customerId > 0) {
    $customerName = (string) (\App\Core\Session::get('customer_name') ?? 'Hesabım');
}
$isLoggedIn = $customerName !== null;
$metaDesc = $metaDescription ?? null;
$canonicalUrl = $canonical ?? null;
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $storeName) ?></title>
    <?php if ($metaDesc !== null && $metaDesc !== ''): ?><meta name="description" content="<?= e($metaDesc) ?>"><?php endif; ?>
    <?php if ($canonicalUrl !== null && $canonicalUrl !== ''): ?><link rel="canonical" href="<?= e($canonicalUrl) ?>"><?php endif; ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%9B%92%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="/assets/vendor/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/store.css">
</head>
<body class="d-flex flex-column min-vh-100 store">
<a href="#main" class="visually-hidden-focusable">İçeriğe geç</a>
<?php try { echo \App\Core\View::partial('store/partials/_header', [
    'storeName' => $storeName,
    'navCategories' => $navCategories,
    'cartCount' => $cartCount,
    'isLoggedIn' => $isLoggedIn,
    'customerName' => $customerName,
    'settings' => $settings,
]); } catch (\Throwable) { /* header hatasında sayfa çalışmaya devam eder */ } ?>

<main id="main" class="flex-grow-1 store-main">
    <?php try { echo \App\Core\View::partial('store/partials/_flash', []); } catch (\Throwable) { } ?>
    <?= $content ?>
</main>

<?php try { echo \App\Core\View::partial('store/partials/_footer', ['storeName' => $storeName, 'settings' => $settings]); } catch (\Throwable) { } ?>

<script src="/assets/vendor/jquery.min.js"></script>
<script src="/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="/assets/js/store.js"></script>
<?php if (!empty($jsonLd)): ?><script type="application/ld+json"><?= $jsonLd ?></script><?php endif; ?>
</body>
</html>
