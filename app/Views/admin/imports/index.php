<?php
/**
 * Aktarım geçmişi listesi.
 * Tam yol: app/Views/admin/imports/index.php
 *
 * Değişkenler: $title, $logs, $feeds, $filterFeed, $filterStatus, $page, $perPage, $message, $error
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'imports']); ?>
<?php
$logs = is_array($logs ?? null) ? $logs : [];
$feeds = is_array($feeds ?? null) ? $feeds : [];

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
?>
<h1 class="h3 mb-4"><?= e($title ?? 'Aktarım Geçmişi') ?></h1>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<form method="get" action="/admin/imports" class="row g-2 align-items-end mb-3">
    <div class="col-12 col-md-auto">
        <label for="filter_feed" class="form-label small mb-1">Kaynak</label>
        <select id="filter_feed" name="feed" class="form-select form-select-sm">
            <option value="">Tümü</option>
            <?php foreach ($feeds as $fd): ?>
                <option value="<?= e($fd['id'] ?? '') ?>"<?= (string) ($filterFeed ?? '') === (string) ($fd['id'] ?? '') ? ' selected' : '' ?>>
                    <?= e($fd['name'] ?? '') ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-auto">
        <label for="filter_status" class="form-label small mb-1">Durum</label>
        <select id="filter_status" name="status" class="form-select form-select-sm">
            <option value=""<?= (string) ($filterStatus ?? '') === '' ? ' selected' : '' ?>>hepsi</option>
            <option value="success"<?= (string) ($filterStatus ?? '') === 'success' ? ' selected' : '' ?>>Başarılı</option>
            <option value="partial"<?= (string) ($filterStatus ?? '') === 'partial' ? ' selected' : '' ?>>Kısmi</option>
            <option value="failed"<?= (string) ($filterStatus ?? '') === 'failed' ? ' selected' : '' ?>>Başarısız</option>
            <option value="running"<?= (string) ($filterStatus ?? '') === 'running' ? ' selected' : '' ?>>Sürüyor</option>
        </select>
    </div>
    <div class="col-12 col-md-auto">
        <button type="submit" class="btn btn-primary btn-sm">Uygula</button>
        <a href="/admin/imports" class="btn btn-outline-secondary btn-sm">Temizle</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if ($logs === []): ?>
            <p class="text-body-secondary p-4 mb-0">
                Bu filtreyle eşleşen aktarım kaydı yok. XML kaynaklarını senkronize ettiğinizde kayıtlar burada listelenir.
            </p>
        <?php else: ?>
            <div class="table-responsive" style="max-height: 32rem; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Kaynak</th>
                            <th scope="col">Dosya</th>
                            <th scope="col">Tetikleyici</th>
                            <th scope="col">Durum</th>
                            <th scope="col">Satırlar</th>
                            <th scope="col">Yeni</th>
                            <th scope="col">Günc.</th>
                            <th scope="col">Hata</th>
                            <th scope="col">Pasife</th>
                            <th scope="col">Süre</th>
                            <th scope="col">Tarih</th>
                            <th scope="col">Detay</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php $logId = (int) ($log['id'] ?? 0); ?>
                            <tr>
                                <td><a href="<?= e('/admin/imports/' . $logId) ?>">#<?= e($logId) ?></a></td>
                                <td>
                                    <span class="fw-semibold"><?= e($log['feed_name'] ?? '-') ?></span>
                                    <div class="small text-body-secondary"><?= e($log['supplier_name'] ?? '') ?></div>
                                </td>
                                <td class="text-truncate" style="max-width: 180px;" title="<?= e($log['file_name'] ?? '') ?>">
                                    <?= e($log['file_name'] ?? '-') ?>
                                </td>
                                <td><span class="badge <?= $srcBadge($log['source'] ?? '') ?>"><?= e($log['source'] ?? '-') ?></span></td>
                                <td><span class="badge <?= $statusBadge($log['status'] ?? '') ?>"><?= e($log['status'] ?? '-') ?></span></td>
                                <td><?= e($log['total_rows'] ?? 0) ?></td>
                                <td><?= e($log['created_rows'] ?? 0) ?></td>
                                <td><?= e($log['updated_rows'] ?? 0) ?></td>
                                <td><?= e($log['error_rows'] ?? 0) ?></td>
                                <td><?= e($log['deactivated_rows'] ?? 0) ?></td>
                                <td class="small"><?= $fmtDur($log['duration_ms'] ?? null) ?></td>
                                <td class="small text-body-secondary"><?= $fmtDate($log['created_at'] ?? null) ?></td>
                                <td>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= e('/admin/imports/' . $logId) ?>">Detay</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-body-secondary px-3 py-2 mb-0">
                Sayfa <?= e($page ?? 1) ?> · sayfa başı <?= e($perPage ?? 25) ?> kayıt.
            </p>
        <?php endif; ?>
    </div>
</div>
