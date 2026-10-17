<?php
/**
 * AŞAMA 5 — Admin: kargo takip ekranı (tüm gönderiler).
 * Tam yol: app/Views/admin/shipments/index.php
 * Beklenen: $rows, $total, $statuses, $filter, $q, $page, $lastPage.
 */
$rows      = is_array($rows ?? null) ? $rows : [];
$statuses  = is_array($statuses ?? null) ? $statuses : [];
$filter    = (string) ($filter ?? 'all');
$q         = (string) ($q ?? '');
$page      = max(1, (int) ($page ?? 1));
$lastPage  = max(1, (int) ($lastPage ?? 1));
$total     = (int) ($total ?? 0);

$qs = static fn (array $over = []): string => '?' . http_build_query(array_filter([
    'status' => $over['status'] ?? $filter,
    'q'      => $over['q'] ?? $q,
    'page'   => $over['page'] ?? null,
], static fn ($v) => $v !== null && $v !== ''));

$flashMsg = get_flash('success');
$flashErr = get_flash('error');
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'shipments']); ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <h1 class="h3 mb-0">Kargo Takip</h1>
    <span class="text-body-secondary small">Kargo firması API'si bağlanmadıysa takip numaraları manuel girilir</span>
</div>

<?php if (!empty($flashMsg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?= e((string) $flashMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= e((string) $flashErr) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e($qs(['status' => 'all', 'page' => null])) ?>">Tümü (<?= $total ?>)</a>
    <?php foreach ($statuses as $stKey => $stLabel): ?>
        <a class="btn btn-sm <?= $filter === $stKey ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e($qs(['status' => $stKey, 'page' => null])) ?>"><?= e($stLabel) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="/admin/shipments" class="row g-2 mb-3">
    <input type="hidden" name="status" value="<?= e($filter) ?>">
    <div class="col-12 col-md-4">
        <label class="visually-hidden" for="ship-search">Ara</label>
        <input type="search" id="ship-search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Takip no / sipariş no / kargo firması…">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-primary">Ara</button>
        <?php if ($q !== ''): ?><a class="btn btn-sm btn-link" href="<?= e($qs(['q' => '', 'page' => null])) ?>">Temizle</a><?php endif; ?>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <caption class="visually-hidden">Kargo gönderileri listesi</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Sipariş</th>
                    <th scope="col">Tedarikçi</th>
                    <th scope="col">Kargo Firması</th>
                    <th scope="col">Takip No</th>
                    <th scope="col">Durum</th>
                    <th scope="col">Kargoya Veriliş</th>
                    <th scope="col">Teslim</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $sh): ?>
                    <tr>
                        <td><a class="fw-semibold" href="/admin/orders/<?= rawurlencode((string) ($sh['order_number'] ?? '')) ?>"><?= e((string) ($sh['order_number'] ?? '-')) ?></a></td>
                        <td class="small"><?= e((string) ($sh['supplier_name'] ?? '-')) ?></td>
                        <td class="small"><?= e((string) ($sh['carrier'] ?? '-')) ?></td>
                        <td class="font-monospace small"><?= e((string) ($sh['tracking_number'] ?? '-')) ?></td>
                        <td><span class="badge text-bg-<?= e(\App\Services\Shipping\ShipmentStatus::badge((string) ($sh['status'] ?? ''))) ?>"><?= e(\App\Services\Shipping\ShipmentStatus::label((string) ($sh['status'] ?? ''))) ?></span></td>
                        <td class="small"><?= e(!empty($sh['shipped_at']) ? date('d.m.Y H:i', strtotime((string) $sh['shipped_at'])) : '-') ?></td>
                        <td class="small"><?= e(!empty($sh['delivered_at']) ? date('d.m.Y H:i', strtotime((string) $sh['delivered_at'])) : '-') ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/shipments/<?= (int) $sh['id'] ?>">Düzenle</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Gönderi bulunamadı. Kargo bilgisi tedarikçi siparişleri üzerinden oluşturulur.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($lastPage > 1): ?>
    <nav aria-label="Kargo sayfalama" class="mt-3">
        <ul class="pagination pagination-sm">
            <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($qs(['page' => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
