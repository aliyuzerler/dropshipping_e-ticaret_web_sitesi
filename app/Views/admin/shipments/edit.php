<?php
/**
 * AŞAMA 5 — Admin: kargo düzenleme ekranı (manuel takip numarası girişi dahil).
 * Tam yol: app/Views/admin/shipments/edit.php
 * Beklenen: $shipment, $so, $statuses (LABELS), $allowed (ALL), $adapterManual.
 */
$shipment = is_array($shipment ?? null) ? $shipment : [];
$so       = is_array($so ?? null) ? $so : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
$allowed  = is_array($allowed ?? null) ? $allowed : ['preparing', 'shipped', 'in_transit', 'delivered', 'exception', 'returned'];
$adapterManual = (bool) ($adapterManual ?? true);
$currentStatus = (string) ($shipment['status'] ?? 'preparing');

$flashMsg = get_flash('success');
$flashErr = get_flash('error');
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'shipments']); ?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/admin/shipments">Kargo Takip</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e((string) ($shipment['order_number'] ?? ('Gönderi #' . (int) ($shipment['id'] ?? 0)))) ?></li>
    </ol>
</nav>

<?php if (!empty($flashMsg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?= e((string) $flashMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= e((string) $flashErr) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Gönderi Bilgisi</h2></div>
            <div class="card-body p-4">
                <form method="post" action="/admin/shipments/<?= (int) ($shipment['id'] ?? 0) ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="sh-status" class="form-label">Gönderi Durumu <span class="text-danger">*</span></label>
                        <select class="form-select" id="sh-status" name="status" required>
                            <?php foreach ($allowed as $stKey): ?>
                                <?php
                                $permitted = \App\Services\Shipping\ShipmentStatus::can($currentStatus, $stKey);
                                ?>
                                <option value="<?= e($stKey) ?>" <?= $stKey === $currentStatus ? 'selected' : '' ?> <?= !$permitted ? 'disabled' : '' ?>>
                                    <?= e($statuses[$stKey] ?? $stKey) ?><?= !$permitted ? ' (geçersiz geçiş)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Geçişler doğrulanır: Hazırlanıyor → Kargoya Verildi → Yolda → Teslim.</div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="sh-carrier" class="form-label">Kargo Firması</label>
                            <input type="text" class="form-control" id="sh-carrier" name="carrier" maxlength="80"
                                   value="<?= e((string) ($shipment['carrier'] ?? '')) ?>" placeholder="Yurtiçi / Aras / MNG…">
                        </div>
                        <div class="col-6">
                            <label for="sh-tracking" class="form-label">Takip Numarası</label>
                            <input type="text" class="form-control" id="sh-tracking" name="tracking_number" maxlength="100"
                                   value="<?= e((string) ($shipment['tracking_number'] ?? '')) ?>" placeholder="manuel giriş">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="sh-url" class="form-label">Takip Bağlantısı</label>
                        <input type="url" class="form-control" id="sh-url" name="tracking_url" maxlength="500"
                               value="<?= e((string) ($shipment['tracking_url'] ?? '')) ?>" placeholder="https://…">
                    </div>
                    <div class="mb-3">
                        <label for="sh-note" class="form-label">Not</label>
                        <input type="text" class="form-control" id="sh-note" name="note" maxlength="500"
                               value="<?= e((string) ($shipment['note'] ?? '')) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">Kaydet ve Siparişi Eşitle</button>
                    <a class="btn btn-outline-secondary" href="/admin/shipments">Vazgeç</a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Bağlı Sipariş</h2></div>
            <div class="card-body p-4">
                <dl class="row mb-0 small">
                    <dt class="col-5">Ana Sipariş</dt>
                    <dd class="col-7"><a href="/admin/orders/<?= rawurlencode((string) ($shipment['order_number'] ?? '')) ?>"><?= e((string) ($shipment['order_number'] ?? '-')) ?></a></dd>
                    <dt class="col-5">Tedarikçi Siparişi</dt>
                    <dd class="col-7 font-monospace"><?= e((string) ($shipment['internal_reference'] ?? '-')) ?></dd>
                    <dt class="col-5">Tedarikçi</dt>
                    <dd class="col-7"><?= e((string) ($shipment['supplier_name'] ?? '-')) ?></dd>
                    <dt class="col-5">Kargoya Veriliş</dt>
                    <dd class="col-7"><?= e(!empty($shipment['shipped_at']) ? date('d.m.Y H:i', strtotime((string) $shipment['shipped_at'])) : '-') ?></dd>
                    <dt class="col-5">Teslim</dt>
                    <dd class="col-7"><?= e(!empty($shipment['delivered_at']) ? date('d.m.Y H:i', strtotime((string) $shipment['delivered_at'])) : '-') ?></dd>
                    <dt class="col-5">Son Güncelleme</dt>
                    <dd class="col-7"><?= e(!empty($shipment['updated_at']) ? date('d.m.Y H:i', strtotime((string) $shipment['updated_at'])) : '-') ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($adapterManual): ?>
            <div class="alert alert-info mb-0">
                <strong>Manuel kargo modu.</strong> Gerçek kargo firması API'si bağlanmadı — takip numarası buradan
                <em>manuel</em> girilir ve müşteri sipariş takip sayfasında gösterilir. API bağlandığında
                ShippingAdapterFactory üzerinden adaptör kaydı yeterlidir; shipments:sync cron'u otomatik devreye girer.
            </div>
        <?php endif; ?>
    </div>
</div>
