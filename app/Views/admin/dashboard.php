<?php
/**
 * Admin panel ana sayfa.
 * Tam yol: app/Views/admin/dashboard.php
 *
 * Değişkenler: $title, $adminName, $counts, $lastImports
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'dashboard']); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <h1 class="h3 mb-0">Yönetim Paneli</h1>
    <div class="d-flex gap-2">
        <a href="/admin/feeds" class="btn btn-outline-primary btn-sm">XML Kaynakları</a>
        <a href="/admin/xml" class="btn btn-primary btn-sm">XML Yükleri</a>
        <form method="post" action="/admin/logout" class="d-inline">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-secondary btn-sm">Çıkış</button>
        </form>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php foreach (['suppliers' => 'Tedarikçi', 'xml_feeds' => 'XML Feed', 'products' => 'Ürün', 'categories' => 'Kategori', 'xml_import_logs' => 'İthalat Kaydı'] as $key => $label): ?>
        <div class="col-6 col-md-4 col-lg">
            <div class="card h-100 shadow-sm">
                <div class="card-body text-center py-3">
                    <p class="display-6 fw-semibold mb-0"><?= e($counts[$key] ?? 0) ?></p>
                    <p class="text-body-secondary small mb-0"><?= e($label) ?></p>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h5 mb-0">Son XML İthalatları</h2>
    </div>
    <div class="card-body p-0">
        <?php if ($lastImports === []): ?>
            <p class="text-body-secondary p-3 mb-0">Henüz ithalat kaydı yok.</p>
        <?php else: ?>
            <div class="table-responsive" style="max-height: 24rem; overflow-y: auto;">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Dosya</th>
                            <th scope="col">Kaynak</th>
                            <th scope="col">Durum</th>
                            <th scope="col">Satır</th>
                            <th scope="col">Tarih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lastImports as $log): ?>
                            <tr>
                                <td><?= e($log['id'] ?? '') ?></td>
                                <td class="text-truncate" style="max-width:220px" title="<?= e($log['file_name'] ?? '') ?>"><?= e($log['file_name'] ?? '') ?></td>
                                <td><span class="badge text-bg-secondary"><?= e($log['source'] ?? '') ?></span></td>
                                <td>
                                    <?php $status = (string) ($log['status'] ?? ''); ?>
                                    <span class="badge <?= $status === 'success' ? 'text-bg-success' : ($status === 'failed' ? 'text-bg-danger' : 'text-bg-warning') ?>">
                                        <?= e($status) ?>
                                    </span>
                                </td>
                                <td><?= e($log['total_rows'] ?? 0) ?></td>
                                <td class="small text-body-secondary"><?= e($log['created_at'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
