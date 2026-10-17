<?php
/**
 * AŞAMA 5 — Admin: tedarikçi siparişi detay + aksiyonlar.
 * Tam yol: app/Views/admin/supplier_orders/show.php
 * Beklenen: $so (satır + join alanları), $items, $delivery (snapshot dizisi),
 * $orderStatuses, $soStatuses.
 */
$so        = is_array($so ?? null) ? $so : [];
$items     = is_array($items ?? null) ? $items : [];
$delivery  = is_array($delivery ?? null) ? $delivery : [];
$soStatus  = (string) ($so['status'] ?? '');
$soRef     = (string) ($so['internal_reference'] ?? '');
$isSent    = in_array($soStatus, ['sent', 'confirmed'], true);

$flashMsg = get_flash('success');
$flashErr = get_flash('error');
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'supplier_orders']); ?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/admin/supplier-orders">Tedarikçi Siparişleri</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= e($soRef !== '' ? $soRef : '#' . (int) ($so['id'] ?? 0)) ?></li>
    </ol>
</nav>

<?php if (!empty($flashMsg)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?= e((string) $flashMsg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>
<?php if (!empty($flashErr)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= e((string) $flashErr) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Sipariş Bilgisi</h2>
                <span class="badge text-bg-<?= e(\App\Services\Supplier\SupplierOrderStatus::badge($soStatus)) ?>"><?= e(\App\Services\Supplier\SupplierOrderStatus::label($soStatus)) ?></span>
            </div>
            <div class="card-body p-4">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Ana Sipariş</dt>
                    <dd class="col-sm-7"><a href="/admin/orders/<?= rawurlencode((string) ($so['order_number'] ?? '')) ?>" class="fw-semibold"><?= e((string) ($so['order_number'] ?? '-')) ?></a>
                        <span class="badge text-bg-<?= e(order_status_badge((string) ($so['order_status'] ?? ''))) ?> ms-1"><?= e(order_status_label((string) ($so['order_status'] ?? ''))) ?></span>
                    </dd>
                    <dt class="col-sm-5">Dahili Referans (idempotency anahtarı)</dt>
                    <dd class="col-sm-7 font-monospace small"><?= e($soRef) ?></dd>
                    <dt class="col-sm-5">Tedarikçi</dt>
                    <dd class="col-sm-7"><?= e((string) ($so['supplier_name'] ?? '-')) ?>
                        <span class="badge text-bg-<?= ($so['integration_type'] ?? '') === 'api' ? 'success' : 'secondary' ?>"><?= ($so['integration_type'] ?? '') === 'api' ? 'API' : 'Manuel' ?></span>
                    </dd>
                    <dt class="col-sm-5">Tedarikçi Referansı</dt>
                    <dd class="col-sm-7"><?= $so['external_reference'] !== null && $so['external_reference'] !== '' ? '<span class="font-monospace small">' . e((string) $so['external_reference']) . '</span>' : '<span class="text-body-secondary">-</span>' ?></dd>
                    <dt class="col-sm-5">Gönderim Yolu</dt>
                    <dd class="col-sm-7"><?= e(match ((string) ($so['sent_by'] ?? '')) { 'api' => 'API', 'manual' => 'Manuel (yönetici)', default => '-' }) ?></dd>
                    <dt class="col-sm-5">Oluşturma / Gönderim</dt>
                    <dd class="col-sm-7 small">
                        <?= e(!empty($so['created_at']) ? date('d.m.Y H:i', strtotime((string) $so['created_at'])) : '-') ?>
                        &nbsp;→&nbsp;
                        <?= e(!empty($so['sent_at']) ? date('d.m.Y H:i', strtotime((string) $so['sent_at'])) : '-') ?>
                    </dd>
                    <dt class="col-sm-5">Son Durum Zamanı</dt>
                    <dd class="col-sm-7 small"><?= e(!empty($so['status_updated_at']) ? date('d.m.Y H:i', strtotime((string) $so['status_updated_at'])) : '-') ?></dd>
                    <dt class="col-sm-5">Deneme Sayısı</dt>
                    <dd class="col-sm-7"><?= (int) ($so['attempts'] ?? 0) ?></dd>
                    <?php if (!empty($so['error_message'])): ?>
                        <dt class="col-sm-5 text-danger">Hata Mesajı</dt>
                        <dd class="col-sm-7"><code class="small text-danger"><?= e((string) $so['error_message']) ?></code></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Kalemler (tedarikçi kodları + alış maliyetleri)</h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Ürün</th>
                            <th scope="col">Tedarikçi Kodu</th>
                            <th scope="col" class="text-center">Adet</th>
                            <th scope="col" class="text-end">Birim Maliyet</th>
                            <th scope="col" class="text-end">Toplam</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td class="small"><?= e((string) ($item['product_name_snapshot'] ?? '-')) ?></td>
                                <td class="font-monospace small"><?= e((string) ($item['supplier_sku'] ?? '-')) ?></td>
                                <td class="text-center"><?= (int) $item['quantity'] ?></td>
                                <td class="text-end small"><?= money($item['unit_cost']) ?></td>
                                <td class="text-end small fw-semibold"><?= money($item['line_total']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($items === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">Kalem yok.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Aksiyonlar</h2></div>
            <div class="card-body p-4">
                <?php if (!$isSent && in_array($soStatus, ['pending', 'error', 'unknown'], true)): ?>
                    <form method="post" action="/admin/supplier-orders/<?= (int) $so['id'] ?>/send" class="mb-3">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary w-100">API Tedarikçisine Gönder</button>
                        <div class="form-text">Yalnız API entegrasyonu olan tedarikçiler için. Manuel tedarikçide kayıt görev olarak kalır.</div>
                    </form>

                    <form method="post" action="/admin/supplier-orders/<?= (int) $so['id'] ?>/mark-sent" class="border-top pt-3">
                        <?= csrf_field() ?>
                        <h3 class="h6">Manuel Gönderildi İşaretle</h3>
                        <div class="mb-2">
                            <label class="form-label small" for="ms-ref">Tedarikçi Referansı</label>
                            <input type="text" class="form-control form-control-sm" id="ms-ref" name="external_reference" maxlength="100" placeholder="örn. TDP-2026-4567">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small" for="ms-note">Not</label>
                            <input type="text" class="form-control form-control-sm" id="ms-note" name="note" maxlength="500" placeholder="örn. e-posta ile iletildi">
                        </div>
                        <button type="submit" class="btn btn-outline-success w-100">Manuel Gönderildi Olarak İşaretle</button>
                    </form>

                    <?php if (in_array($soStatus, ['unknown', 'sending'], true)): ?>
                        <form method="post" action="/admin/supplier-orders/<?= (int) $so['id'] ?>/query" class="border-top pt-3 mt-3">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-primary w-100">Tedarikçiden Durum Sorgula</button>
                            <div class="form-text">Zaman aşımında yeniden gönderim YAPILMAZ — önce durum sorgulanır.</div>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($soStatus, ['error', 'unknown'], true)): ?>
                        <form method="post" action="/admin/supplier-orders/<?= (int) $so['id'] ?>/retry" class="border-top pt-3 mt-3" data-confirm="Kayıt yeniden gönderim kuyruğuna alınacak. Durum belirsizse önce tedarikçiden sorgulayın. Devam?">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-warning w-100">Hata/Belirsiz → Yeniden Kuyruğa Al</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-body-secondary mb-2 small">Bu kayıt iletildi (<?= e(\App\Services\Supplier\SupplierOrderStatus::label($soStatus)) ?>).</p>
                    <?php if (in_array($soStatus, ['sent', 'confirmed'], true)): ?>
                        <a class="btn btn-outline-primary w-100" href="/admin/shipments/new?so=<?= (int) $so['id'] ?>">Kargo Gönderisi Yönet</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Teslimat Bilgisi (snapshot)</h2></div>
            <div class="card-body p-4">
                <?php if ($delivery === []): ?>
                    <p class="text-body-secondary small mb-0">Teslimat snapshot'ı kaydedilmemiş.</p>
                <?php else: ?>
                    <dl class="row mb-0 small">
                        <dt class="col-5">Alıcı</dt><dd class="col-7"><?= e((string) ($delivery['full_name'] ?? '-')) ?></dd>
                        <dt class="col-5">İl / İlçe</dt><dd class="col-7"><?= e((string) ($delivery['city'] ?? '-')) ?> / <?= e((string) ($delivery['district'] ?? '-')) ?></dd>
                        <dt class="col-5">Posta Kodu</dt><dd class="col-7"><?= e((string) ($delivery['postal_code'] ?? '-')) ?></dd>
                        <dt class="col-5">Adres</dt><dd class="col-7"><?= e((string) ($delivery['line1'] ?? '')) ?><?= !empty($delivery['line2']) ? ', ' . e((string) $delivery['line2']) : '' ?></dd>
                        <dt class="col-5">Telefon</dt><dd class="col-7"><?= e((string) ($delivery['phone'] ?? '-')) ?></dd>
                    </dl>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
