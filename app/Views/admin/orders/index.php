<?php
/**
 * AŞAMA 4 — Admin: sipariş listesi (durum/arama filtreli).
 * Tam yol: app/Views/admin/orders/index.php
 * Beklenen: $orders, $statusCounts, $statuses, $filterStatus, $q, $page,
 * $lastPage, $total, $pendingRefunds.
 */
$orders     = is_array($orders ?? null) ? $orders : [];
$statuses   = is_array($statuses ?? null) ? $statuses : [];
/** @var array<string,int> $statusCounts */
$statusCounts = is_array($statusCounts ?? null) ? $statusCounts : [];
$filterStatus = (string) ($filterStatus ?? '');
$q          = (string) ($q ?? '');
$page       = max(1, (int) ($page ?? 1));
$lastPage   = max(1, (int) ($lastPage ?? 1));
$total      = (int) ($total ?? 0);
$pendingRefunds = (int) ($pendingRefunds ?? 0);

$qs = static fn (array $over = []): string => '?' . http_build_query(array_filter([
    'status' => $over['status'] ?? $filterStatus,
    'q'      => $over['q'] ?? $q,
    'page'   => $over['page'] ?? null,
], static fn ($v) => $v !== null && $v !== ''));
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'orders']); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <h1 class="h3 mb-0">Siparişler
        <?php if ($pendingRefunds > 0): ?>
            <span class="badge text-bg-warning align-middle"><?= $pendingRefunds ?> iade talebi onay bekliyor</span>
        <?php endif; ?>
    </h1>
</div>

<!-- Durum filtreleri -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-sm <?= $filterStatus === '' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e($qs(['status' => '', 'page' => null])) ?>">Tümü (<?= (int) array_sum($statusCounts) ?>)</a>
    <?php foreach ($statuses as $stKey => $stLabel): ?>
        <a class="btn btn-sm <?= $filterStatus === $stKey ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e($qs(['status' => $stKey, 'page' => null])) ?>">
            <?= e($stLabel) ?> (<?= (int) ($statusCounts[$stKey] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</div>

<form method="get" action="/admin/orders" class="row g-2 mb-3">
    <?php if ($filterStatus !== ''): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
    <div class="col-12 col-md-4">
        <label class="visually-hidden" for="order-search">Sipariş ara</label>
        <input type="search" id="order-search" name="q" value="<?= e($q) ?>" class="form-control form-control-sm" placeholder="Sipariş no veya müşteri e-postası…">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-primary">Ara</button>
        <?php if ($q !== ''): ?><a class="btn btn-sm btn-link" href="<?= e($qs(['q' => '', 'page' => null])) ?>">Temizle</a><?php endif; ?>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <caption class="visually-hidden">Sipariş listesi — durum filtreli ve sayfalı</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Sipariş No</th>
                    <th scope="col">Müşteri</th>
                    <th scope="col">Tarih</th>
                    <th scope="col" class="text-center">Kalem</th>
                    <th scope="col" class="text-end">Tutar</th>
                    <th scope="col">Durum</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="fw-semibold"><?= e((string) ($o['order_number'] ?? '')) ?></td>
                        <td class="small"><?= e((string) ($o['customer_email'] ?? '-')) ?></td>
                        <td class="small"><?= e(!empty($o['placed_at']) ? date('d.m.Y H:i', strtotime((string) $o['placed_at'])) : '-') ?></td>
                        <td class="text-center"><?= (int) ($o['item_count'] ?? 0) ?></td>
                        <td class="text-end fw-semibold"><?= money($o['grand_total'] ?? 0) ?></td>
                        <td><span class="badge text-bg-<?= e(order_status_badge($o['status'] ?? '')) ?>"><?= e(order_status_label($o['status'] ?? '')) ?></span></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/admin/orders/<?= rawurlencode((string) ($o['order_number'] ?? '')) ?>">Detay</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($orders === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Kayıt bulunamadı.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($lastPage > 1): ?>
    <nav aria-label="Sipariş sayfalama" class="mt-3">
        <ul class="pagination pagination-sm">
            <?php for ($p = 1; $p <= $lastPage; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= e($qs(['page' => $p])) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>
