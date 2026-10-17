<?php
/**
 * Sipariş detayı — snapshot tabanlı (ad/SKU/fiyat/adres sipariş anındaki veriler).
 * Tam yol: app/Views/store/account/order_detail.php
 * Beklenen: $order, $items, $shipping, $billing, $payments, $refunds.
 * AŞAMA 4: ödeme bilgisi, "Ödemeyi Tamamla" yönlendirmesi, iade talebi formu,
 * durum etiketleri OrderStatus durum makinesinden gelir.
 */
use App\Services\Order\OrderStatus;

$order = is_array($order ?? null) ? $order : [];
$items = is_array($items ?? null) ? $items : [];
/** @var array<string,mixed> $shipping */
$shipping = is_array($shipping ?? null) ? $shipping : [];
/** @var array<string,mixed> $billing */
$billing  = is_array($billing ?? null) ? $billing : [];
/** @var list<array<string,mixed>> $payments */
$payments = is_array($payments ?? null) ? $payments : [];
/** @var list<array<string,mixed>> $refunds */
$refunds  = is_array($refunds ?? null) ? $refunds : [];

$orderStatus = (string) ($order['status'] ?? '');
$status = [order_status_label($orderStatus), order_status_badge($orderStatus)];
$isPayable  = in_array($orderStatus, OrderStatus::payable(), true);
$isRefundable = in_array($orderStatus, OrderStatus::refundRequestable(), true);

/** Adres snapshot'ını okunur satırlara çevirir (eksik alanlara dayanıklı). */
$addressLines = static function (array $a): array {
    $lines = [];
    $name = trim((string) ($a['full_name'] ?? ''));
    if ($name !== '') { $lines[] = $name; }
    $line1 = trim((string) ($a['line1'] ?? ''));
    if ($line1 !== '') { $lines[] = $line1; }
    $line2 = trim((string) ($a['line2'] ?? ''));
    if ($line2 !== '') { $lines[] = $line2; }
    $district = trim((string) ($a['district'] ?? ''));
    $city = trim((string) ($a['city'] ?? ''));
    $place = trim($district . '/' . $city, '/');
    if ($place !== '') { $lines[] = $place; }
    $postal = trim((string) ($a['postal_code'] ?? ''));
    if ($postal !== '') { $lines[] = $postal; }
    $country = trim((string) ($a['country_code'] ?? ''));
    if ($country !== '') { $lines[] = $country; }
    $phone = trim((string) ($a['phone'] ?? ''));
    if ($phone !== '') { $lines[] = '☎ ' . $phone; }
    return $lines;
};

$shippingLines = $addressLines($shipping);
$billingLines  = $addressLines($billing);
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item"><a href="/hesabim">Hesabım</a></li>
            <li class="breadcrumb-item"><a href="/hesabim/siparisler">Siparişlerim</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= e((string) ($order['order_number'] ?? '')) ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h1 class="section-title mb-0">Sipariş <?= e((string) ($order['order_number'] ?? '')) ?></h1>
        <span class="badge text-bg-<?= e($status[1]) ?>"><?= e($status[0]) ?></span>
    </div>
    <p class="small text-muted">
        <?php if (!empty($order['placed_at'])): ?>
            Sipariş tarihi: <?= e(date('d.m.Y H:i', strtotime((string) $order['placed_at']))) ?>
        <?php endif; ?>
        <?php if (!empty($order['shipping_method'])): ?> · Kargo: <?= e((string) $order['shipping_method']) ?><?php endif; ?>
    </p>

    <?php if ($isPayable): ?>
        <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2" role="alert">
            <span>💳 Ödemeniz bekliyor<?= $orderStatus === OrderStatus::PAYMENT_FAILED ? ' — son deneme başarısız oldu' : '' ?>. Siparişinizi tamamlamak için ödeme adımına geçin.</span>
            <a class="btn btn-primary btn-sm" href="/odeme/onay/<?= rawurlencode((string) ($order['order_number'] ?? '')) ?>">Ödemeyi Tamamla (iyzico)</a>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <!-- Kalemler -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-bold">Sipariş Kalemleri</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <caption class="visually-hidden">Sipariş kalemleri — sipariş anındaki ad, SKU ve fiyat bilgileri</caption>
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Ürün</th>
                                <th scope="col">SKU</th>
                                <th scope="col" class="text-center">Adet</th>
                                <th scope="col" class="text-end">Birim Fiyat</th>
                                <th scope="col" class="text-end">Satır Toplamı</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e((string) ($item['product_name_snapshot'] ?? '')) ?></td>
                                    <td class="small text-muted"><?= e((string) ($item['sku_snapshot'] ?? '')) ?></td>
                                    <td class="text-center"><?= (int) ($item['quantity'] ?? 0) ?></td>
                                    <td class="text-end"><?= money($item['unit_price'] ?? 0) ?></td>
                                    <td class="text-end fw-semibold"><?= money($item['line_total'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($items === []): ?>
                                <tr><td colspan="5" class="text-center text-muted py-3">Kalem bilgisi bulunamadı.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (trim((string) ($order['note'] ?? '')) !== ''): ?>
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white fw-bold">Sipariş Notum</div>
                    <div class="card-body"><?= nl2br(e((string) $order['note'])) ?></div>
                </div>
            <?php endif; ?>

            <!-- AŞAMA 5: Kargo takibi (paket paket) -->
            <?php
            /** @var list<array<string,mixed>> $shipments */
            $shipments = is_array($shipments ?? null) ? $shipments : [];
            $hasShipmentData = false;
            foreach ($shipments as $shp) {
                if (($shp['status'] ?? '') !== 'preparing' || !empty($shp['tracking_number'])) {
                    $hasShipmentData = true;
                    break;
                }
            }
            ?>
            <?php if ($shipments !== []): ?>
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-white fw-bold">🚚 Kargo Takibi</div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($shipments as $shp): ?>
                            <?php
                            $shpStatus = (string) ($shp['status'] ?? 'preparing');
                            if ($shpStatus === 'preparing' && empty($shp['tracking_number'])) {
                                continue; // henüz kargoya verilmemiş paketi sessizce atla
                            }
                            $hasShipmentData = true;
                            ?>
                            <li class="list-group-item">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <span class="small fw-semibold"><?= e((string) ($shp['supplier_name'] ?? 'Tedarikçi')) ?> paketi</span>
                                    <span class="badge text-bg-<?= e(\App\Services\Shipping\ShipmentStatus::badge($shpStatus)) ?>"><?= e(\App\Services\Shipping\ShipmentStatus::label($shpStatus)) ?></span>
                                </div>
                                <?php if (!empty($shp['carrier']) || !empty($shp['tracking_number'])): ?>
                                    <div class="small text-muted mt-1">
                                        <?= !empty($shp['carrier']) ? 'Kargo: <strong>' . e((string) $shp['carrier']) . '</strong>' : '' ?>
                                        <?= !empty($shp['tracking_number']) ? (!empty($shp['carrier']) ? ' · ' : '') . 'Takip No: <span class="font-monospace">' . e((string) $shp['tracking_number']) . '</span>' : '' ?>
                                    </div>
                                <?php endif; ?>
                                <div class="small text-muted">
                                    <?php if (!empty($shp['shipped_at'])): ?>Kargoya veriliş: <?= e(date('d.m.Y H:i', strtotime((string) $shp['shipped_at']))) ?><?php endif; ?>
                                    <?php if (!empty($shp['delivered_at'])): ?> · Teslim: <?= e(date('d.m.Y H:i', strtotime((string) $shp['delivered_at']))) ?><?php endif; ?>
                                </div>
                                <?php if (!empty($shp['tracking_url'])): ?>
                                    <a class="btn btn-sm btn-outline-brand mt-2" href="<?= e((string) $shp['tracking_url']) ?>" rel="noopener nofollow" target="_blank">Kargo Firmasında Takip Et</a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (!$hasShipmentData): ?>
                            <li class="list-group-item small text-muted">Paketleriniz hazırlanıyor. Kargoya verildiğinde burada takip bilgisi görünecek.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Adresler -->
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-header bg-white fw-bold">📦 Teslimat Adresi</div>
                        <div class="card-body">
                            <?php if ($shippingLines === []): ?>
                                <p class="small text-muted mb-0">Adres bilgisi bulunamadı.</p>
                            <?php else: ?>
                                <address class="small mb-0" style="font-style:normal">
                                    <?php foreach ($shippingLines as $line): ?><?= e($line) ?><br><?php endforeach; ?>
                                </address>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-header bg-white fw-bold">🧾 Fatura Adresi</div>
                        <div class="card-body">
                            <?php if ($billingLines === []): ?>
                                <p class="small text-muted mb-0">Adres bilgisi bulunamadı.</p>
                            <?php else: ?>
                                <address class="small mb-0" style="font-style:normal">
                                    <?php foreach ($billingLines as $line): ?><?= e($line) ?><br><?php endforeach; ?>
                                </address>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Toplamlar -->
        <div class="col-12 col-lg-4">
            <div class="summary-card p-3 bg-white">
                <h2 class="h5 mb-3">Sipariş Toplamı</h2>
                <div class="summary-row"><span>Ara Toplam</span><strong><?= money($order['items_total'] ?? 0) ?></strong></div>
                <?php if ((float) ($order['discount_total'] ?? 0) > 0): ?>
                    <div class="summary-row text-success">
                        <span>İndirim<?= (string) ($order['coupon_code'] ?? '') !== '' ? ' (' . e((string) $order['coupon_code']) . ')' : '' ?></span>
                        <strong>−<?= money($order['discount_total'] ?? 0) ?></strong>
                    </div>
                <?php endif; ?>
                <div class="summary-row"><span>Kargo</span><strong><?= (float) ($order['shipping_total'] ?? 0) > 0 ? money($order['shipping_total'] ?? 0) : 'Ücretsiz' ?></strong></div>
                <div class="summary-row"><span>KDV</span><strong><?= money($order['tax_total'] ?? 0) ?></strong></div>
                <hr>
                <div class="summary-row summary-total"><span>Genel Toplam</span><span><?= money($order['grand_total'] ?? 0) ?></span></div>
                <p class="small text-muted mb-0">Fiyatlar KDV dahildir.</p>
            </div>

            <!-- AŞAMA 4: Ödeme bilgisi -->
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-header bg-white fw-bold">💳 Ödeme</div>
                <div class="card-body p-0">
                    <?php if ($payments === []): ?>
                        <p class="small text-muted p-3 mb-0">Henüz ödeme başlatılmadı.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($payments as $pay): ?>
                                <li class="list-group-item small">
                                    <div class="d-flex justify-content-between"><span class="fw-semibold">iyzico</span><span class="badge text-bg-<?= (string) $pay['status'] === 'success' ? 'success' : (((string) $pay['status']) === 'initiated' ? 'warning' : 'danger') ?>"><?= e(payment_status_label($pay['status'] ?? '')) ?></span></div>
                                    <div class="text-muted"><?= money($pay['amount'] ?? 0) ?> · <?= e(date('d.m.Y H:i', strtotime((string) ($pay['created_at'] ?? 'now')))) ?></div>
                                    <?php if ((string) ($pay['card_last_four'] ?? '') !== ''): ?><div class="text-muted">Kart: **** <?= e((string) $pay['card_last_four']) ?><?= (string) ($pay['card_association'] ?? '') !== '' ? ' (' . e((string) $pay['card_association']) . ')' : '' ?></div><?php endif; ?>
                                    <?php if ((string) ($pay['error_message'] ?? '') !== ''): ?><div class="text-danger"><?= e((string) $pay['error_message']) ?></div><?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <!-- AŞAMA 4: iade talebi -->
            <?php if ($isRefundable): ?>
                <div class="card shadow-sm border-0 mt-3">
                    <div class="card-header bg-white fw-bold">↩️ İade Talebi</div>
                    <div class="card-body">
                        <form method="post" action="/hesabim/siparisler/<?= rawurlencode((string) ($order['order_number'] ?? '')) ?>/iade-talebi">
                            <?= csrf_field() ?>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold" for="refund-scope">Kapsam</label>
                                <select id="refund-scope" name="scope" class="form-select form-select-sm">
                                    <option value="full">Tüm siparişin iadesi</option>
                                    <option value="partial">Kısmi iade (kalem seçerek)</option>
                                </select>
                            </div>
                            <div class="mb-2 d-none" data-partial-fields>
                                <label class="form-label small fw-semibold" for="refund-item">Sipariş kalemi</label>
                                <select id="refund-item" name="order_item_id" class="form-select form-select-sm">
                                    <?php foreach ($items as $it): ?>
                                        <option value="<?= (int) ($it['id'] ?? 0) ?>"><?= e(mb_substr((string) ($it['product_name_snapshot'] ?? ''), 0, 40, 'UTF-8')) ?> — <?= money($it['line_total'] ?? 0) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2 d-none" data-partial-fields>
                                <label class="form-label small fw-semibold" for="refund-amount">Tutar (₺)</label>
                                <input type="text" inputmode="decimal" id="refund-amount" name="amount" class="form-control form-control-sm" placeholder="0,00">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-semibold" for="refund-reason">Gerekçe</label>
                                <textarea id="refund-reason" name="reason" class="form-control form-control-sm" rows="2" maxlength="255" placeholder="İade gerekçenizi kısaca yazın"></textarea>
                            </div>
                            <button type="submit" class="btn btn-outline-brand btn-sm w-100">İade Talebi Gönder</button>
                            <p class="small text-muted mt-2 mb-0">Talebiniz yönetici onayına düşer; onaylanınca iade iyzico üzerinden işlenir.</p>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($refunds !== []): ?>
                <div class="card shadow-sm border-0 mt-3">
                    <div class="card-header bg-white fw-bold">İade Kayıtları</div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($refunds as $rf): ?>
                            <li class="list-group-item small">
                                <div class="d-flex justify-content-between">
                                    <span><?= ($rf['type'] ?? '') === 'cancel' ? 'Ödeme İptali' : (($rf['scope'] ?? '') === 'partial' ? 'Kısmi İade' : 'Tam İade') ?></span>
                                    <span class="badge text-bg-<?= ($rf['status'] ?? '') === 'completed' ? 'success' : ((($rf['status'] ?? '') === 'pending') ? 'warning' : 'danger') ?>"><?= e(refund_status_label($rf['status'] ?? '')) ?></span>
                                </div>
                                <div class="text-muted"><?= money($rf['amount'] ?? 0) ?><?= (string) ($rf['refund_reference'] ?? '') !== '' ? ' · Ref: ' . e((string) $rf['refund_reference']) : '' ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-4 d-flex flex-wrap gap-2">
        <a class="btn btn-outline-brand" href="/hesabim/siparisler">&larr; Siparişlerime Dön</a>
        <?php if ($isPayable): ?>
            <a class="btn btn-primary" href="/odeme/onay/<?= rawurlencode((string) ($order['order_number'] ?? '')) ?>">Ödemeyi Tamamla (iyzico)</a>
        <?php endif; ?>
    </div>
</div>
<script>
/* Kısmi iade alanlarını kapsam seçimine göre aç/kapat (progresif geliştirme) */
(function () {
    var scope = document.getElementById('refund-scope');
    if (!scope) { return; }
    var toggle = function () {
        document.querySelectorAll('[data-partial-fields]').forEach(function (el) {
            el.classList.toggle('d-none', scope.value !== 'partial');
        });
    };
    scope.addEventListener('change', toggle);
    toggle();
})();
</script>
