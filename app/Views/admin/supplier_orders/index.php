<?php
/**
 * AŞAMA 5 — Admin: tedarikçi siparişleri listesi.
 * "Tedarikçiye iletilecek siparişler", "API gönderim hataları",
 * "Manuel işlem bekleyen" ve "Durum belirsiz" filtreleriyle.
 * Tam yol: app/Views/admin/supplier_orders/index.php
 * Beklenen: $rows, $total, $counts, $statuses, $filter, $suppliers,
 * $supplierId, $q, $page, $lastPage, $manualTasks, $notifications.
 */
$rows        = is_array($rows ?? null) ? $rows : [];
$statuses    = is_array($statuses ?? null) ? $statuses : [];
$counts      = is_array($counts ?? null) ? $counts : [];
$suppliers   = is_array($suppliers ?? null) ? $suppliers : [];
$notifCounts = is_array($notifications ?? null) ? $notifications : [];
$filter      = (string) ($filter ?? 'all');
$supplierId  = (int) ($supplierId ?? 0);
$q           = (string) ($q ?? '');
$page        = max(1, (int) ($page ?? 1));
$lastPage    = max(1, (int) ($lastPage ?? 1));
$total       = (int) ($total ?? 0);
$manualTasks = (int) ($manualTasks ?? 0);

$qs = static fn (array $over = []): string => '?' . http_build_query(array_filter([
    'status'   => $over['status'] ?? $filter,
    'supplier' => $over['supplier'] ?? ($supplierId > 0 ? $supplierId : null),
    'q'        => $over['q'] ?? $q,
    'page'     => $over['page'] ?? null,
], static fn ($v) => $v !== null && $v !== ''));

$flashMsg = get_flash('success');
$flashErr = get_flash('error');
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'supplier_orders']); ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <h1 class="h3 mb-0">Tedarikçi Siparişleri
        <?php if ($manualTasks > 0): ?>
            <span class="badge text-bg-warning align-middle"><?= $manualTasks ?> manuel işlem bekliyor</span>
        <?php endif; ?>
        <?php if (($notifCounts['pending'] ?? 0) > 0 || ($notifCounts['failed'] ?? 0) > 0): ?>
            <span class="badge text-bg-info align-middle">Bildirim kuyruğu: <?= (int) ($notifCounts['pending'] ?? 0) ?> bekleyen / <?= (int) ($notifCounts['failed'] ?? 0) ?> hatalı</span>
        <?php endif; ?>
    </h1>
</div>

<?php if (!empty($flashMsg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?= e((string) $flashMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= e((string) $flashErr) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>

<!-- Durum filtreleri -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e($qs(['status' => 'all', 'page' => null])) ?>">Tümü (<?= (int) array_sum($counts) ?>)</a>
    <?php foreach ($statuses as $stKey => $stLabel): ?>
        <a class="btn btn-sm <?= $filter === $stKey ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e($qs(['status' => $stKey, 'page' => null])) ?>">
            <?= e($stLabel) ?> (<?= (int) ($counts[$stKey] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</div>

<form method="get" action="/admin/supplier-orders" class="row g-2 mb-3">
    <input type="hidden" name="status" value="<?= e($filter) ?>">
    <div class="col-12 col-md-4">
        <label class="visually-hidden" for="so-search">Ara</label>
        <input type="search" id="so-search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Sipariş no / dahili referans / tedarikçi referansı…">
    </div>
    <div class="col-12 col-md-3">
        <label class="visually-hidden" for="so-supplier">Tedarikçi</label>
        <select id="so-supplier" name="supplier" class="form-select form-select-sm">
            <option value="0">Tüm tedarikçiler</option>
            <?php foreach ($suppliers as $sup): ?>
                <option value="<?= (int) $sup['id'] ?>" <?= $supplierId === (int) $sup['id'] ? 'selected' : '' ?>><?= e((string) $sup['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-primary">Filtrele</button>
        <?php if ($q !== '' || $supplierId > 0): ?><a class="btn btn-sm btn-link" href="<?= e($qs(['q' => '', 'supplier' => null, 'page' => null])) ?>">Temizle</a><?php endif; ?>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <caption class="visually-hidden">Tedarikçi siparişleri listesi</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Dahili Referans</th>
                    <th scope="col">Sipariş</th>
                    <th scope="col">Tedarikçi</th>
                    <th scope="col">Entegrasyon</th>
                    <th scope="col" class="text-end">Maliyet</th>
                    <th scope="col">Durum</th>
                    <th scope="col">Deneme</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $so): ?>
                    <tr>
                        <td class="small font-monospace"><?= e((string) ($so['internal_reference'] ?? '-')) ?></td>
                        <td>
                            <a class="fw-semibold" href="/admin/orders/<?= rawurlencode((string) ($so['order_number'] ?? '')) ?>"><?= e((string) ($so['order_number'] ?? '-')) ?></a>
                            <div class="small text-body-secondary"><?= e(\App\Services\Order\OrderStatus::label((string) ($so['order_status'] ?? ''))) ?></div>
                        </td>
                        <td class="small"><?= e((string) ($so['supplier_name'] ?? '-')) ?></td>
                        <td><span class="badge text-bg-<?= ($so['integration_type'] ?? '') === 'api' ? 'success' : 'secondary' ?>"><?= ($so['integration_type'] ?? '') === 'api' ? 'API' : 'Manuel' ?></span></td>
                        <td class="text-end small"><?= money($so['grand_total'] ?? 0) ?></td>
                        <td>
                            <span class="badge text-bg-<?= e(\App\Services\Supplier\SupplierOrderStatus::badge((string) ($so['status'] ?? ''))) ?>"><?= e(\App\Services\Supplier\SupplierOrderStatus::label((string) ($so['status'] ?? ''))) ?></span>
                            <?php if (!empty($so['error_message'])): ?>
                                <div class="small text-danger mt-1" style="max-width: 16rem;"><?= e(mb_substr((string) $so['error_message'], 0, 90)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center small"><?= (int) ($so['attempts'] ?? 0) ?></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/supplier-orders/<?= (int) $so['id'] ?>">Detay</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($lastPage > 1): ?>
    <nav aria-label="Tedarikçi siparişi sayfalama" class="mt-3">
        <ul class="pagination pagination-sm">
            <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($qs(['page' => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
