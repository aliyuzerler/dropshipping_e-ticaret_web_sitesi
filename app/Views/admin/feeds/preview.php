<?php
/**
 * Aktarım önizlemesi (veritabanına yazmadan eşleme sonucu).
 * Tam yol: app/Views/admin/feeds/preview.php
 *
 * Değişkenler: $title, $feed, $preview (array|null), $hasFile, $message, $error
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'feeds']); ?>
<?php
$feed = is_array($feed ?? null) ? $feed : [];
$feedId = (int) ($feed['id'] ?? 0);
$preview = is_array($preview ?? null) ? $preview : null;
$hasFile = !empty($hasFile);
$rows = is_array($preview['rows'] ?? null) ? $preview['rows'] : [];
$problems = is_array($preview['problems'] ?? null) ? $preview['problems'] : [];

$fmt = static function (mixed $value, int $dec = 2): string {
    return $value === null || $value === '' ? '-' : e(number_format((float) $value, $dec, ',', '.'));
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= e($title ?? 'Aktarım Önizlemesi') ?></h1>
    <a href="<?= e('/admin/feeds/' . $feedId . '/mapping') ?>" class="btn btn-outline-secondary btn-sm">Eşlemeye Dön</a>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if (!$hasFile): ?>
    <div class="alert alert-warning" role="alert">
        Bu kaynak için henüz XML dosyası yüklenmemiş.
        <a href="<?= e('/admin/feeds/' . $feedId . '/mapping') ?>">Eşleme ekranından</a> örnek dosya yükleyin.
    </div>
<?php elseif ($preview === null): ?>
    <div class="alert alert-warning" role="alert">Önizleme üretilemedi.</div>
<?php else: ?>
    <p class="text-body-secondary">
        Toplam <strong><?= e($preview['total_seen'] ?? 0) ?></strong> ürün düğümü okundu —
        eşleme profili: <strong><?= e($preview['mapping_count'] ?? 0) ?></strong> alan.
    </p>

    <?php if ($problems !== []): ?>
        <div class="alert alert-warning" role="alert">
            <strong>Eşleme sorunları:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($problems as $problem): ?>
                    <li class="small"><?= e((string) $problem) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 32rem; overflow-y: auto;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">SKU</th>
                            <th scope="col">Ürün</th>
                            <th scope="col">Kategori</th>
                            <th scope="col">Marka</th>
                            <th scope="col">Alış</th>
                            <th scope="col">TRY Karşılığı</th>
                            <th scope="col">KDV %</th>
                            <th scope="col">Stok</th>
                            <th scope="col">Hesaplanan Satış</th>
                            <th scope="col">Marj %</th>
                            <th scope="col">Görseller</th>
                            <th scope="col">Varyantlar</th>
                            <th scope="col">Kurallar</th>
                            <th scope="col">Sorunlar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="15" class="text-center text-body-secondary py-4">
                                    Gösterilecek satır yok. Eşleme profilini kontrol edin veya farklı bir dosya yükleyin.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $imgs = is_array($row['images'] ?? null) ? $row['images'] : [];
                                $firstImg = (string) ($imgs[0] ?? '');
                                $imgList = implode(' | ', $imgs);
                                $variants = is_array($row['variants'] ?? null) ? $row['variants'] : [];
                                $applied = is_array($row['applied_rules'] ?? null) ? $row['applied_rules'] : [];
                                $rowProblems = is_array($row['problems'] ?? null) ? $row['problems'] : [];
                                $lowMargin = !empty($row['low_margin']);
                                ?>
                                <tr>
                                    <td><?= e($row['line'] ?? '') ?></td>
                                    <td class="fw-semibold"><?= e($row['supplier_sku'] ?? '-') ?></td>
                                    <td><?= e($row['name'] ?? '-') ?></td>
                                    <td><?= e($row['category'] ?? '-') ?></td>
                                    <td><?= e($row['brand'] ?? '-') ?></td>
                                    <td>
                                        <?= $row['cost_original'] !== null && $row['cost_original'] !== ''
                                            ? e(number_format((float) $row['cost_original'], 2, ',', '.')) . ' ' . e($row['source_currency'] ?? 'TRY')
                                            : '-' ?>
                                    </td>
                                    <td><?= $fmt($row['cost_try'] ?? null) ?></td>
                                    <td><?= $fmt($row['vat'] ?? null) ?><?= ($row['vat'] ?? null) !== null && ($row['vat'] ?? null) !== '' ? '%' : '' ?></td>
                                    <td><?= ($row['stock'] ?? null) !== null ? e($row['stock']) : '-' ?></td>
                                    <td><strong><?= $fmt($row['sale_price'] ?? null) ?></strong></td>
                                    <td>
                                        <?php if (($row['margin'] ?? null) === null || ($row['margin'] ?? null) === ''): ?>
                                            -
                                        <?php else: ?>
                                            <span class="badge <?= $lowMargin ? 'text-bg-danger' : 'text-bg-success' ?>">
                                                <?= e(number_format((float) $row['margin'], 2, ',', '.')) ?>%
                                            </span>
                                            <?php if ($lowMargin): ?>
                                                <div class="small text-danger">düşük marj</div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= count($imgs) ?> görsel
                                        <?php if ($firstImg !== ''): ?>
                                            <div class="small text-body-secondary text-truncate" style="max-width: 170px;" title="<?= e($imgList) ?>">
                                                <?= e(mb_substr($firstImg, 0, 40)) ?><?= mb_strlen($firstImg) > 40 ? '…' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $variants !== [] ? e(implode(', ', $variants)) : '-' ?></td>
                                    <td class="small text-body-secondary"><?= $applied !== [] ? e(implode(', ', $applied)) : '-' ?></td>
                                    <td class="small text-danger"><?= $rowProblems !== [] ? e(implode('; ', $rowProblems)) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <p class="small text-body-secondary mt-2 mb-0">
        Önizleme veritabanına YAZMAZ; ilk <?= e(count($rows)) ?> satır gösterilir (toplam okunan: <?= e($preview['total_seen'] ?? 0) ?>).
        Görsel listeleri <code>|</code> ile ayrılır.
    </p>
<?php endif; ?>
