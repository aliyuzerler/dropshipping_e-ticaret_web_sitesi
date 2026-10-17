<?php
/**
 * Siparişlerim listesi — sayfalı (10/sayfa).
 * Tam yol: app/Views/store/account/orders.php
 * Beklenen: $orders (list<array<string,mixed>>: order_number, placed_at,
 * item_count, grand_total, status), $page, $lastPage, $total.
 * Mobil: liste görünümü (d-lg-none) + masaüstü: tablo (d-none d-lg-block).
 */
$orders   = is_array($orders ?? null) ? $orders : [];
$page     = max(1, (int) ($page ?? 1));
$lastPage = max(1, (int) ($lastPage ?? 1));
$total    = (int) ($total ?? 0);

/** AŞAMA 4: durum etiketleri OrderStatus durum makinesinden gelir. */
$statusOf = static function (mixed $s): array {
    return [order_status_label($s), order_status_badge($s)];
};

$detailUrl = static fn (mixed $number): string => '/hesabim/siparisler/' . e((string) $number);
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item"><a href="/hesabim">Hesabım</a></li>
            <li class="breadcrumb-item active" aria-current="page">Siparişlerim</li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="section-title mb-0">Siparişlerim</h1>
        <span class="small text-muted">Toplam <?= $total ?> sipariş</span>
    </div>

    <?php if ($orders === []): ?>
        <div class="alert alert-light border" role="alert">
            🧾 Henüz siparişiniz yok.
            <a href="/">Alışverişe başlamak için tıklayın</a>.
        </div>
    <?php else: ?>

        <!-- Masaüstü: tablo -->
        <div class="card shadow-sm border-0 d-none d-lg-block">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <caption class="visually-hidden">Sipariş listesi, sayfa <?= $page ?>/<?= $lastPage ?></caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Sipariş No</th>
                            <th scope="col">Tarih</th>
                            <th scope="col" class="text-center">Ürün Sayısı</th>
                            <th scope="col" class="text-end">Tutar</th>
                            <th scope="col">Durum</th>
                            <th scope="col" class="text-end"><span class="visually-hidden">İşlemler</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o): ?>
                            <?php $status = $statusOf($o['status'] ?? ''); ?>
                            <tr>
                                <td class="fw-semibold"><?= e((string) ($o['order_number'] ?? '')) ?></td>
                                <td><?= e(!empty($o['placed_at']) ? date('d.m.Y H:i', strtotime((string) $o['placed_at'])) : '-') ?></td>
                                <td class="text-center"><?= (int) ($o['item_count'] ?? 0) ?></td>
                                <td class="text-end fw-semibold"><?= money($o['grand_total'] ?? 0) ?></td>
                                <td><span class="badge text-bg-<?= e($status[1]) ?>"><?= e($status[0]) ?></span></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-brand" href="<?= $detailUrl($o['order_number'] ?? '') ?>">Detay</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobil: liste -->
        <div class="d-lg-none">
            <div class="list-group shadow-sm">
                <?php foreach ($orders as $o): ?>
                    <?php $status = $statusOf($o['status'] ?? ''); ?>
                    <a class="list-group-item list-group-item-action" href="<?= $detailUrl($o['order_number'] ?? '') ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold"><?= e((string) ($o['order_number'] ?? '')) ?></span>
                            <span class="badge text-bg-<?= e($status[1]) ?>"><?= e($status[0]) ?></span>
                        </div>
                        <div class="d-flex justify-content-between small text-muted">
                            <span><?= e(!empty($o['placed_at']) ? date('d.m.Y H:i', strtotime((string) $o['placed_at'])) : '-') ?> · <?= (int) ($o['item_count'] ?? 0) ?> ürün</span>
                            <span class="fw-bold text-body"><?= money($o['grand_total'] ?? 0) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sayfalama -->
        <?php if ($lastPage > 1): ?>
            <nav aria-label="Sipariş sayfalama" class="mt-4">
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= max(1, $page - 1) ?>" aria-label="Önceki sayfa">&laquo;</a>
                    </li>
                    <?php for ($i = 1; $i <= $lastPage; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>>
                            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= min($lastPage, $page + 1) ?>" aria-label="Sonraki sayfa">&raquo;</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
