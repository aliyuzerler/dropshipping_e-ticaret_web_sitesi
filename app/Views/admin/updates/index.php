<?php
/**
 * Son fiyat ve stok güncellemeleri (+ kâr eşiği uyarıları).
 * Tam yol: app/Views/admin/updates/index.php
 *
 * Değişkenler: $title, $updates, $onlyLowMargin, $message, $error
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'updates']); ?>
<?php
$updates = is_array($updates ?? null) ? $updates : [];
$onlyLowMargin = !empty($onlyLowMargin);
$threshold = (float) config('xml.pricing.profit_warn_percent', 10.0);

$fmtDate = static function (mixed $value): string {
    $v = trim((string) ($value ?? ''));
    return $v !== '' ? e(substr($v, 0, 16)) : '-';
};
$pairHtml = static function (mixed $old, mixed $new, int $dec = 2): string {
    $o = $old === null || $old === '' ? '-' : e(number_format((float) $old, $dec, ',', '.'));
    $n = $new === null || $new === '' ? '-' : e(number_format((float) $new, $dec, ',', '.'));
    $arrow = '';
    if ($old !== null && $old !== '' && $new !== null && $new !== '' && (float) $old !== (float) $new) {
        $arrow = (float) $new > (float) $old
            ? ' <span class="text-success" title="arttı">&#9650;</span>'
            : ' <span class="text-danger" title="azaldı">&#9660;</span>';
    }
    return $o . ' &rarr; ' . $n . $arrow;
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= e($title ?? 'Fiyat ve Stok Güncellemeleri') ?></h1>
    <?php if (!$onlyLowMargin): ?>
        <a href="/admin/updates?low=1" class="btn btn-outline-danger btn-sm">Yalnız Kâr Uyarıları</a>
    <?php else: ?>
        <a href="/admin/updates" class="btn btn-outline-secondary btn-sm">Tümünü Göster</a>
    <?php endif; ?>
</div>

<div class="alert alert-info small" role="alert">
    Kâr eşiği uyarısı: hesaplanan satış fiyatındaki kâr marjı
    <?= e(number_format($threshold, 2, ',', '.')) ?>% altına düşen güncellemeler kırmızı işaretlenir.
    Bu bir uyarıdır; aktarım engellenmez.
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if ($updates === []): ?>
            <p class="text-body-secondary p-4 mb-0">
                Henüz fiyat/stok güncellemesi yok. XML kaynaklarını senkronize ettiğinizde
                değişen fiyat ve stoklar burada listelenir.
            </p>
        <?php else: ?>
            <div class="table-responsive" style="max-height: 32rem; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Zaman</th>
                            <th scope="col">SKU</th>
                            <th scope="col">Ürün</th>
                            <th scope="col">Tedarikçi</th>
                            <th scope="col">Alış</th>
                            <th scope="col">Satış</th>
                            <th scope="col">Stok</th>
                            <th scope="col">Marj %</th>
                            <th scope="col">Not</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($updates as $update): ?>
                            <tr>
                                <td class="small text-body-secondary"><?= $fmtDate($update['created_at'] ?? null) ?></td>
                                <td class="fw-semibold"><?= e($update['sku'] ?? '-') ?></td>
                                <td><?= e($update['product_name'] ?? '-') ?></td>
                                <td><?= e($update['supplier_name'] ?? '-') ?></td>
                                <td><?= $pairHtml($update['old_cost'] ?? null, $update['new_cost'] ?? null) ?></td>
                                <td><?= $pairHtml($update['old_sale_price'] ?? null, $update['new_sale_price'] ?? null) ?></td>
                                <td><?= $pairHtml($update['old_stock'] ?? null, $update['new_stock'] ?? null, 0) ?></td>
                                <td>
                                    <?= ($update['profit_margin'] ?? null) !== null && ($update['profit_margin'] ?? null) !== ''
                                        ? e(number_format((float) $update['profit_margin'], 2, ',', '.')) . '%'
                                        : '-' ?>
                                </td>
                                <td>
                                    <?= !empty($update['is_low_margin'])
                                        ? '<span class="badge text-bg-danger">Kâr eşiği altı</span>'
                                        : '<span class="text-body-secondary">-</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
