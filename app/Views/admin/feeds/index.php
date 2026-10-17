<?php
/**
 * XML kaynakları listesi.
 * Tam yol: app/Views/admin/feeds/index.php
 *
 * Değişkenler: $title, $feeds, $message, $error
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'feeds']); ?>
<?php
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
$fmtDate = static function (mixed $value): string {
    $v = trim((string) ($value ?? ''));
    return $v !== '' ? e(substr($v, 0, 16)) : '-';
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= e($title ?? 'XML Kaynakları') ?></h1>
    <a href="/admin/feeds/new" class="btn btn-primary btn-sm">Yeni Kaynak</a>
</div>

<div class="alert alert-info alert-dismissible fade show small" role="alert">
    Akış özeti: <strong>URL</strong> kaynakları cron ile (<code>php bin/console xml:sync --all</code>),
    <strong>yükleme</strong> kaynakları panelden dosya yüklenerek senkronize edilir.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($feeds === []): ?>
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h2 class="h5 card-title">Henüz XML kaynağı yok</h2>
            <p class="text-body-secondary mb-3">Ürün aktarım akışı altı adımdan oluşur. İlk kaynağınızı oluşturarak başlayın.</p>
            <ol class="mb-4">
                <li>Tedarikçi ekle</li>
                <li>XML Kaynağı oluştur</li>
                <li>Dosya yükle</li>
                <li>Alan eşleştir</li>
                <li>Önizle</li>
                <li>Senkronize et</li>
            </ol>
            <a href="/admin/feeds/new" class="btn btn-primary">İlk Kaynağı Oluştur</a>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 32rem; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Kaynak</th>
                            <th scope="col">Tip</th>
                            <th scope="col">Periyot</th>
                            <th scope="col">Ürün</th>
                            <th scope="col">Son Senkron</th>
                            <th scope="col">Son Durum</th>
                            <th scope="col">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feeds as $feed): ?>
                            <?php
                            $feedId = (int) ($feed['id'] ?? 0);
                            $isActive = (int) ($feed['is_active'] ?? 0) === 1;
                            $lastLog = is_array($feed['last_log'] ?? null) ? $feed['last_log'] : null;
                            $lastSync = (string) ($feed['last_success_sync_at'] ?? '') !== ''
                                ? $feed['last_success_sync_at']
                                : ($feed['last_import_at'] ?? null);
                            ?>
                            <tr>
                                <td><?= e($feedId) ?></td>
                                <td>
                                    <span class="fw-semibold"><?= e($feed['name'] ?? '') ?></span>
                                    <div class="small text-body-secondary"><?= e($feed['supplier_name'] ?? '') ?></div>
                                </td>
                                <td>
                                    <?php if ((string) ($feed['source_type'] ?? '') === 'url'): ?>
                                        <span class="badge text-bg-primary">URL</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">Yükleme</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($feed['interval_minutes'] ?? '') ?> dk</td>
                                <td><?= e($feed['product_count'] ?? 0) ?></td>
                                <td class="small"><?= $fmtDate($lastSync) ?></td>
                                <td>
                                    <?php if ($lastLog !== null): ?>
                                        <span class="badge <?= $statusBadge($lastLog['status'] ?? '') ?>"><?= e($lastLog['status'] ?? '') ?></span>
                                        <div class="small text-body-secondary">
                                            yeni <?= e($lastLog['created_rows'] ?? 0) ?> ·
                                            günc <?= e($lastLog['updated_rows'] ?? 0) ?> ·
                                            hata <?= e($lastLog['error_rows'] ?? 0) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-body-secondary">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="Kaynak işlemleri">
                                        <a class="btn btn-outline-primary btn-sm" href="<?= e('/admin/feeds/' . $feedId . '/edit') ?>">Düzenle</a>
                                        <a class="btn btn-outline-secondary btn-sm" href="<?= e('/admin/feeds/' . $feedId . '/mapping') ?>">Eşleştirme</a>
                                        <a class="btn btn-outline-info btn-sm" href="<?= e('/admin/feeds/' . $feedId . '/preview') ?>">Önizleme</a>
                                        <form method="post" action="<?= e('/admin/feeds/' . $feedId . '/sync') ?>" class="d-inline"
                                              data-confirm="Senkronizasyon şimdi başlatılsın mı? XML dosyası yeniden okunur ve ürünler güncellenir.">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-primary btn-sm">Senkronize Et</button>
                                        </form>
                                        <form method="post" action="<?= e('/admin/feeds/' . $feedId . '/toggle') ?>" class="d-inline">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-sm <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                                <?= $isActive ? 'Pasife Al' : 'Aktifleştir' ?>
                                            </button>
                                        </form>
                                        <form method="post" action="<?= e('/admin/feeds/' . $feedId . '/delete') ?>" class="d-inline"
                                              data-confirm="Bu kaynak ve eşleme profili silinecek; geçmiş korunur. Onaylıyor musunuz?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="btn btn-outline-danger btn-sm">Sil</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
