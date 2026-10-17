<?php
/**
 * Aktarım detayı (sayaçlar, hatalar, fiyat/stok değişimleri).
 * Tam yol: app/Views/admin/imports/show.php
 *
 * Değişkenler: $title, $log, $changes, $message, $error
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'imports']); ?>
<?php
$log = is_array($log ?? null) ? $log : [];
$changes = is_array($changes ?? null) ? $changes : [];
$logId = (int) ($log['id'] ?? 0);

$statusBadge = static function (mixed $status): string {
    return match ((string) $status) {
        'running' => 'text-bg-warning',
        'success' => 'text-bg-success',
        'partial' => 'text-bg-info',
        'failed'  => 'text-bg-danger',
        default   => 'text-bg-secondary',
    };
};
$srcBadge = static function (mixed $source): string {
    return match ((string) $source) {
        'cron'   => 'text-bg-secondary',
        'upload' => 'text-bg-info',
        'manual' => 'text-bg-primary',
        'cli'    => 'text-bg-dark',
        default  => 'text-bg-secondary',
    };
};
$fmtDur = static function (mixed $ms): string {
    if ($ms === null || $ms === '') {
        return '-';
    }
    $ms = (int) $ms;
    return $ms >= 1000
        ? e(number_format($ms / 1000, 2, ',', '.')) . ' sn'
        : e(number_format($ms, 0, ',', '.')) . ' ms';
};
$fmtDate = static function (mixed $value): string {
    $v = trim((string) ($value ?? ''));
    return $v !== '' ? e(substr($v, 0, 16)) : '-';
};
$pair = static function (mixed $old, mixed $new, int $dec = 2): string {
    $o = $old === null || $old === '' ? '-' : e(number_format((float) $old, $dec, ',', '.'));
    $n = $new === null || $new === '' ? '-' : e(number_format((float) $new, $dec, ',', '.'));
    return $o . ' &rarr; ' . $n;
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= e($title ?? 'Aktarım Detayı') ?></h1>
    <a href="/admin/imports" class="btn btn-outline-secondary btn-sm">&larr; Geçmişe Dön</a>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Toplam Satır', $log['total_rows'] ?? 0],
        ['Yeni', $log['created_rows'] ?? 0],
        ['Güncellenen', $log['updated_rows'] ?? 0],
        ['Atlanan', $log['skipped_rows'] ?? 0],
        ['Hata', $log['error_rows'] ?? 0],
        ['Pasife Alınan', $log['deactivated_rows'] ?? 0],
    ] as $stat): ?>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card shadow-sm h-100">
                <div class="card-body p-3 text-center">
                    <p class="h4 fw-semibold mb-0"><?= e($stat[1]) ?></p>
                    <p class="text-body-secondary small mb-0"><?= e($stat[0]) ?></p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Aktarım Bilgileri</h2></div>
    <div class="card-body p-3">
        <table class="table table-sm align-middle mb-0">
            <tbody>
                <tr>
                    <th scope="row" class="text-body-secondary fw-normal" style="width: 11rem;">Kaynak</th>
                    <td>
                        <span class="fw-semibold"><?= e($log['feed_name'] ?? '-') ?></span>
                        <span class="text-body-secondary small">(<?= e($log['supplier_name'] ?? '-') ?>)</span>
                    </td>
                </tr>
                <tr>
                    <th scope="row" class="text-body-secondary fw-normal">Dosya</th>
                    <td><?= e($log['file_name'] ?? '-') ?></td>
                </tr>
                <tr>
                    <th scope="row" class="text-body-secondary fw-normal">Tetikleyici</th>
                    <td><span class="badge <?= $srcBadge($log['source'] ?? '') ?>"><?= e($log['source'] ?? '-') ?></span></td>
                </tr>
                <tr>
                    <th scope="row" class="text-body-secondary fw-normal">Durum</th>
                    <td><span class="badge <?= $statusBadge($log['status'] ?? '') ?>"><?= e($log['status'] ?? '-') ?></span></td>
                </tr>
                <tr>
                    <th scope="row" class="text-body-secondary fw-normal">Başlangıç</th>
                    <td><?= $fmtDate($log['started_at'] ?? null) ?></td>
                </tr>
                <tr>
                    <th scope="row" class="text-body-secondary fw-normal">Bitiş</th>
                    <td><?= $fmtDate($log['finished_at'] ?? null) ?></td>
                </tr>
                <tr>
                    <th scope="row" class="text-body-secondary fw-normal">Süre</th>
                    <td><?= $fmtDur($log['duration_ms'] ?? null) ?></td>
                </tr>
                <tr>
                    <th scope="row" class="text-body-secondary fw-normal">Kayıt Tarihi</th>
                    <td><?= $fmtDate($log['created_at'] ?? null) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($log['error_message'])): ?>
    <div class="alert alert-danger" role="alert">
        <strong>Hata detayı:</strong>
        <div class="mt-1"><?= nl2br(e($log['error_message'])) ?></div>
    </div>
<?php endif; ?>
<?php if (!empty($log['warnings'])): ?>
    <div class="alert alert-warning" role="alert">
        <strong>Uyarılar:</strong>
        <div class="mt-1"><?= nl2br(e($log['warnings'])) ?></div>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Fiyat / Stok Değişiklikleri</h2></div>
    <div class="card-body p-0">
        <?php if ($changes === []): ?>
            <p class="text-body-secondary p-4 mb-0">Bu aktarımda fiyat/stok değişikliği kaydedilmedi.</p>
        <?php else: ?>
            <div class="table-responsive" style="max-height: 32rem; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">SKU</th>
                            <th scope="col">Ürün</th>
                            <th scope="col">Eski &rarr; Yeni Alış</th>
                            <th scope="col">Eski &rarr; Yeni Satış</th>
                            <th scope="col">Eski &rarr; Yeni Stok</th>
                            <th scope="col">Marj %</th>
                            <th scope="col">Kâr Uyarısı</th>
                            <th scope="col">Zaman</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($changes as $change): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($change['sku'] ?? '-') ?></td>
                                <td><?= e($change['product_name'] ?? '-') ?></td>
                                <td><?= $pair($change['old_cost'] ?? null, $change['new_cost'] ?? null) ?></td>
                                <td><?= $pair($change['old_sale_price'] ?? null, $change['new_sale_price'] ?? null) ?></td>
                                <td><?= $pair($change['old_stock'] ?? null, $change['new_stock'] ?? null, 0) ?></td>
                                <td>
                                    <?= ($change['profit_margin'] ?? null) !== null && ($change['profit_margin'] ?? null) !== ''
                                        ? e(number_format((float) $change['profit_margin'], 2, ',', '.')) . '%'
                                        : '-' ?>
                                </td>
                                <td>
                                    <?= !empty($change['is_low_margin'])
                                        ? '<span class="badge text-bg-danger">Kâr eşiği altı</span>'
                                        : '<span class="text-body-secondary">-</span>' ?>
                                </td>
                                <td class="small text-body-secondary"><?= $fmtDate($change['created_at'] ?? null) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
