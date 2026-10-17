<?php
/**
 * Admin alt navigasyonu (Aşama 2 ekranları).
 * Tam yol: app/Views/admin/_nav.php
 *
 * Değişkenler: $active (string: dashboard|feeds|imports|rules|updates|xml)
 */

$navItems = [
    'dashboard' => ['/admin', 'Panel'],
    'orders'    => ['/admin/orders', 'Siparişler'],
    'supplier_orders' => ['/admin/supplier-orders', 'Tedarikçi Siparişleri'],
    'shipments' => ['/admin/shipments', 'Kargo'],
    'reports'   => ['/admin/reports/suppliers', 'Raporlar'],
    'suppliers' => ['/admin/suppliers', 'Tedarikçiler'],
    'feeds'     => ['/admin/feeds', 'XML Kaynakları'],
    'imports'   => ['/admin/imports', 'Aktarım Geçmişi'],
    'rules'     => ['/admin/price-rules', 'Fiyat Kuralları'],
    'updates'   => ['/admin/updates', 'Güncellemeler'],
    'xml'       => ['/admin/xml', 'XML Yükleri'],
    'settings'  => ['/admin/settings', 'Mağaza Ayarları'],
];
$navActive = (string) ($active ?? '');
?>
<ul class="nav nav-pills mb-4 bg-light p-2 rounded flex-wrap" aria-label="Yönetim bölümleri">
    <?php foreach ($navItems as $navKey => $navItem): ?>
        <li class="nav-item">
            <a class="nav-link<?= $navKey === $navActive ? ' active' : '' ?>" href="<?= e($navItem[0]) ?>"><?= e($navItem[1]) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
