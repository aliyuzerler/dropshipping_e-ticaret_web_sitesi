<?php
/**
 * AŞAMA 5 — Admin: tedarikçi bazında sipariş raporları + bildirim özeti.
 * Tam yol: app/Views/admin/reports/suppliers.php
 * Beklenen: $rows (rapor satırları), $notifCounts, $recentNotifications.
 */
$rows     = is_array($rows ?? null) ? $rows : [];
$notifCounts = is_array($notifCounts ?? null) ? $notifCounts : [];
$recent   = is_array($recentNotifications ?? null) ? $recentNotifications : [];
$hasAny   = false;
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'reports']); ?>

<h1 class="h3 mb-4">Tedarikçi Sipariş Raporları</h1>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Tedarikçi Bazında Özet</h2></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <caption class="visually-hidden">Tedarikçi sipariş raporu</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Tedarikçi</th>
                    <th scope="col">Entegrasyon</th>
                    <th scope="col" class="text-center">Toplam</th>
                    <th scope="col" class="text-center">Bekleyen</th>
                    <th scope="col" class="text-center">İletilen</th>
                    <th scope="col" class="text-center">Hata</th>
                    <th scope="col" class="text-center">Belirsiz</th>
                    <th scope="col" class="text-center">İptal</th>
                    <th scope="col" class="text-end">Alış Maliyeti (iletildi)</th>
                    <th scope="col" class="text-end">Ort. Gönderim</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): $hasAny = $hasAny || (int) $r['total_orders'] > 0; ?>
                    <tr>
                        <td class="fw-semibold"><?= e((string) $r['name']) ?> <span class="text-body-secondary small">(<?= e((string) $r['code']) ?>)</span></td>
                        <td><span class="badge text-bg-<?= ($r['integration_type'] ?? '') === 'api' ? 'success' : 'secondary' ?>"><?= ($r['integration_type'] ?? '') === 'api' ? 'API' : 'Manuel' ?></span></td>
                        <td class="text-center"><?= (int) $r['total_orders'] ?></td>
                        <td class="text-center"><?= (int) ($r['pending_count'] ?? 0) ?></td>
                        <td class="text-center"><?= (int) ($r['sent_count'] ?? 0) ?></td>
                        <td class="text-center <?= (int) ($r['error_count'] ?? 0) > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (int) ($r['error_count'] ?? 0) ?></td>
                        <td class="text-center <?= (int) ($r['unknown_count'] ?? 0) > 0 ? 'text-warning fw-semibold' : '' ?>"><?= (int) ($r['unknown_count'] ?? 0) ?></td>
                        <td class="text-center"><?= (int) ($r['cancelled_count'] ?? 0) ?></td>
                        <td class="text-end small"><?= money($r['purchase_cost'] ?? 0) ?></td>
                        <td class="text-end small"><?= $r['avg_send_minutes'] !== null ? number_format((float) $r['avg_send_minutes'], 0) . ' dk' : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">Henüz tedarikçi yok.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-lg-5">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Bildirim Kuyruğu</h2></div>
            <div class="card-body p-4">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">Gönderildi <span class="badge text-bg-success"><?= (int) ($notifCounts['sent'] ?? 0) ?></span></li>
                    <li class="list-group-item d-flex justify-content-between">Bekleyen <span class="badge text-bg-warning"><?= (int) ($notifCounts['pending'] ?? 0) ?></span></li>
                    <li class="list-group-item d-flex justify-content-between">Hatalı <span class="badge text-bg-danger"><?= (int) ($notifCounts['failed'] ?? 0) ?></span></li>
                </ul>
                <p class="form-text mb-0 mt-3">Tekrarlı gönderim, notifications.dedup_key UNIQUE kısıtıyla engellenir. Hatalı gönderimler app.log'a yazılır.</p>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Son Bildirimler</h2></div>
            <div class="table-responsive" style="max-height: 26rem; overflow-y: auto;">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th scope="col">#</th><th scope="col">Olay</th><th scope="col">Alıcı</th><th scope="col">Durum</th><th scope="col">Zaman</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $n): ?>
                            <tr>
                                <td class="small"><?= (int) $n['id'] ?></td>
                                <td class="small font-monospace"><?= e((string) $n['event']) ?></td>
                                <td class="small"><?= e((string) $n['recipient_email']) ?></td>
                                <td><span class="badge text-bg-<?= match ((string) $n['status']) { 'sent' => 'success', 'failed' => 'danger', default => 'warning' } ?>"><?= e((string) $n['status']) ?></span></td>
                                <td class="small"><?= e(!empty($n['sent_at']) ? date('d.m H:i', strtotime((string) $n['sent_at'])) : date('d.m H:i', strtotime((string) $n['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($recent === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">Henüz bildirim üretilmedi.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
