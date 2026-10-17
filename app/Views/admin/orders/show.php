<?php
/**
 * AŞAMA 4 — Admin: sipariş detayı + durum yönetimi + iptal/iade + DENETİM İZİ.
 * Tam yol: app/Views/admin/orders/show.php
 * Beklenen: $order, $items, $payments, $refunds, $supplierOrders, $timeline,
 * $allowedTargets, $statuses, $shipping, $billing, $cancellable, $refundable.
 */
use App\Services\Order\OrderStatus;

$order    = is_array($order ?? null) ? $order : [];
$items    = is_array($items ?? null) ? $items : [];
/** @var list<array<string,mixed>> $payments */
$payments = is_array($payments ?? null) ? $payments : [];
/** @var list<array<string,mixed>> $refunds */
$refunds  = is_array($refunds ?? null) ? $refunds : [];
/** @var list<array<string,mixed>> $supplierOrders */
$supplierOrders = is_array($supplierOrders ?? null) ? $supplierOrders : [];
/** @var list<array<string,mixed>> $timeline */
$timeline = is_array($timeline ?? null) ? $timeline : [];
$statuses = is_array($statuses ?? null) ? $statuses : [];
/** @var list<string> $allowedTargets */
$allowedTargets = is_array($allowedTargets ?? null) ? $allowedTargets : [];
/** @var array<string,mixed> $shipping */
$shipping = is_array($shipping ?? null) ? $shipping : [];
/** @var array<string,mixed> $billing */
$billing  = is_array($billing ?? null) ? $billing : [];
/** @var bool $cancellable */
$cancellable = (bool) ($cancellable ?? false);
/** @var bool $refundable */
$refundable  = (bool) ($refundable ?? false);

$status = (string) ($order['status'] ?? '');
$number = (string) ($order['order_number'] ?? '');

$addrLines = static function (array $a): string {
    $parts = array_filter([
        (string) ($a['full_name'] ?? ''),
        (string) ($a['line1'] ?? ''),
        (string) ($a['line2'] ?? ''),
        trim(((string) ($a['district'] ?? '')) . ' / ' . ((string) ($a['city'] ?? '')), ' /'),
        (string) ($a['postal_code'] ?? ''),
        (string) ($a['phone'] ?? ''),
    ], static fn ($v) => trim((string) $v) !== '');
    return implode('<br>', array_map(static fn ($v) => e((string) $v), $parts));
};
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'orders']); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h1 class="h3 mb-1">Sipariş <?= e($number) ?></h1>
        <span class="badge text-bg-<?= e(order_status_badge($status)) ?>"><?= e(order_status_label($status)) ?></span>
        <span class="text-muted small ms-2"><?= e(!empty($order['placed_at']) ? date('d.m.Y H:i', strtotime((string) $order['placed_at'])) : '') ?></span>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="/admin/orders">&larr; Listeye dön</a>
</div>

<div class="row g-4">
    <div class="col-12 col-xl-8">
        <!-- Kalemler -->
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold">Kalemler (snapshot)</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Ürün</th><th>SKU</th><th class="text-center">Adet</th><th class="text-end">Birim</th><th class="text-end">Toplam</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td class="small"><?= e((string) ($it['product_name_snapshot'] ?? '')) ?></td>
                                <td class="small text-muted"><?= e((string) ($it['sku_snapshot'] ?? '')) ?></td>
                                <td class="text-center"><?= (int) ($it['quantity'] ?? 0) ?></td>
                                <td class="text-end small"><?= money($it['unit_price'] ?? 0) ?></td>
                                <td class="text-end small fw-semibold"><?= money($it['line_total'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body small border-top">
                Ara: <strong><?= money($order['items_total'] ?? 0) ?></strong>
                · İndirim: <strong class="text-success">−<?= money($order['discount_total'] ?? 0) ?></strong>
                · Kargo: <strong><?= money($order['shipping_total'] ?? 0) ?></strong>
                · KDV: <strong><?= money($order['tax_total'] ?? 0) ?></strong>
                · <span class="fs-6">Genel: <strong><?= money($order['grand_total'] ?? 0) ?></strong></span>
            </div>
        </div>

        <!-- Ödemeler -->
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold">Ödemeler (iyzico)</div>
            <?php if ($payments === []): ?>
                <div class="card-body small text-muted">Ödeme kaydı yok.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>#</th><th>Durum</th><th>Tutar</th><th>Kart</th><th>Token</th><th>iyzico Ödeme No</th><th>Hata</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td class="small"><?= (int) ($p['id'] ?? 0) ?></td>
                                    <td><span class="badge text-bg-<?= ($p['status'] ?? '') === 'success' ? 'success' : ((($p['status'] ?? '')) === 'initiated' ? 'warning' : 'danger') ?>"><?= e(payment_status_label($p['status'] ?? '')) ?></span></td>
                                    <td class="small"><?= money($p['amount'] ?? 0) ?></td>
                                    <td class="small text-muted"><?= (string) ($p['card_last_four'] ?? '') !== '' ? '**** ' . e((string) $p['card_last_four']) . ' ' . e((string) ($p['card_association'] ?? '')) : '—' ?></td>
                                    <td class="small text-muted font-monospace"><?= e(mb_substr((string) ($p['token'] ?? '—'), 0, 13, 'UTF-8')) ?>…</td>
                                    <td class="small text-muted"><?= e((string) ($p['provider_payment_id'] ?? '—')) ?></td>
                                    <td class="small text-danger"><?= e((string) ($p['error_message'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- İade / iptal kayıtları -->
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold">İptal / İade Kayıtları</div>
            <?php if ($refunds === []): ?>
                <div class="card-body small text-muted">Kayıt yok.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>#</th><th>Tip</th><th>Kapsam</th><th>Tutar</th><th>Durum</th><th>Referans</th><th>İşlem</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($refunds as $rf): ?>
                                <tr>
                                    <td class="small"><?= (int) ($rf['id'] ?? 0) ?></td>
                                    <td class="small"><?= ($rf['type'] ?? '') === 'cancel' ? 'İptal' : 'İade' ?></td>
                                    <td class="small"><?= ($rf['scope'] ?? '') === 'partial' ? 'Kısmi' : 'Tam' ?></td>
                                    <td class="small"><?= money($rf['amount'] ?? 0) ?></td>
                                    <td><span class="badge text-bg-<?= ($rf['status'] ?? '') === 'completed' ? 'success' : ((($rf['status'] ?? '')) === 'pending' ? 'warning' : 'danger') ?>"><?= e(refund_status_label($rf['status'] ?? '')) ?></span></td>
                                    <td class="small font-monospace"><?= e((string) ($rf['refund_reference'] ?? '—')) ?></td>
                                    <td>
                                        <?php if ((string) ($rf['status'] ?? '') === 'pending'): ?>
                                            <form method="post" action="/admin/orders/refunds/<?= (int) ($rf['id'] ?? 0) ?>/approve" class="d-inline">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('iyzico üzerinden <?= ($rf['type'] ?? '') === 'cancel' ? 'İPTAL' : 'İADE' ?> işlemi başlatılacak. Onaylıyor musunuz?')">Onayla</button>
                                            </form>
                                            <form method="post" action="/admin/orders/refunds/<?= (int) ($rf['id'] ?? 0) ?>/reject" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="reason" value="Yönetici reddi">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('İade talebi reddedilsin mi?')">Reddet</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tedarikçi siparişleri -->
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold">Tedarikçi Siparişleri</div>
            <?php if ($supplierOrders === []): ?>
                <div class="card-body small text-muted">Ödeme onaylandıktan sonra otomatik oluşturulur (yalnız bir kez).</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($supplierOrders as $so): ?>
                        <li class="list-group-item small">
                            #<?= (int) ($so['id'] ?? 0) ?> — <?= e((string) ($so['supplier_name'] ?? ('Tedarikçi #' . (int) ($so['supplier_id'] ?? 0)))) ?>
                            <span class="badge text-bg-secondary ms-1"><?= e((string) ($so['status'] ?? '')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- DENETİM İZİ (işlem logları) -->
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold">Denetim İzi (audit log)</div>
            <?php if ($timeline === []): ?>
                <div class="card-body small text-muted">Kayıt yok.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($timeline as $log): ?>
                        <?php
                            $ctx = json_decode((string) ($log['context'] ?? ''), true);
                            $from = is_array($ctx) ? (string) ($ctx['from'] ?? '') : '';
                            $to   = is_array($ctx) ? (string) ($ctx['to'] ?? '') : '';
                            $detail = is_array($ctx) ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : '';
                        ?>
                        <li class="list-group-item small">
                            <span class="text-muted font-monospace"><?= e(date('d.m H:i:s', strtotime((string) ($log['created_at'] ?? 'now')))) ?></span>
                            <span class="fw-semibold ms-1"><?= e((string) ($log['action'] ?? '')) ?></span>
                            <?php if ($from !== '' && $to !== ''): ?>
                                — <?= e(order_status_label($from)) ?> → <strong><?= e(order_status_label($to)) ?></strong>
                            <?php elseif ($detail !== '' && $detail !== 'null'): ?>
                                — <span class="text-muted"><?= e(mb_substr($detail, 0, 140, 'UTF-8')) ?></span>
                            <?php endif; ?>
                            <span class="text-muted">(<?= e((string) ($log['actor_type'] ?? '')) ?><?= (string) ($log['admin_name'] ?? '') !== '' ? ': ' . e((string) $log['admin_name']) : '' ?><?= (string) ($log['ip_address'] ?? '') !== '' ? ' · ' . e((string) $log['ip_address']) : '' ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <!-- Durum yönetimi -->
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold">Durum Yönetimi</div>
            <div class="card-body">
                <form method="post" action="/admin/orders/<?= (int) ($order['id'] ?? 0) ?>/status" class="d-flex gap-2 flex-wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="order_number" value="<?= e($number) ?>">
                    <label class="visually-hidden" for="status-select">Yeni durum</label>
                    <select id="status-select" name="status" class="form-select form-select-sm w-auto">
                        <?php foreach ($allowedTargets as $target): ?>
                            <option value="<?= e($target) ?>"><?= e($statuses[$target] ?? $target) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary" <?= $allowedTargets === [] ? 'disabled' : '' ?>>Geçir</button>
                </form>
                <?php if ($allowedTargets === []): ?>
                    <p class="small text-muted mt-2 mb-0">Bu durumdan geçiş yok (terminal durum).</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- İptal -->
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold">Siparişi İptal Et</div>
            <div class="card-body">
                <?php if ($cancellable): ?>
                    <form method="post" action="/admin/orders/<?= (int) ($order['id'] ?? 0) ?>/cancel">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_number" value="<?= e($number) ?>">
                        <label class="form-label small fw-semibold" for="cancel-reason">İptal gerekçesi</label>
                        <input type="text" id="cancel-reason" name="reason" class="form-control form-control-sm mb-2" maxlength="200" placeholder="Müşteri talebi vb.">
                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Sipariş iptal edilsin mi? Başarılı ödeme varsa iyzico İPTAL işlemi çalıştırılır.')">İptal Et</button>
                        <p class="small text-muted mt-2 mb-0">Ödemesi yapılmış siparişte iyzico <code>/payment/cancel</code> çağrılır ve ödeme iptal edilir.</p>
                    </form>
                <?php else: ?>
                    <p class="small text-muted mb-0">Bu durumda iptal mümkün değil (kargolanmış siparişlerde iade akışı kullanın).</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Adresler -->
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-bold">Adresler</div>
            <div class="card-body small">
                <p class="fw-semibold mb-1">Teslimat</p>
                <p><?= $shipping === [] ? '<span class="text-muted">—</span>' : $addrLines($shipping) ?></p>
                <p class="fw-semibold mb-1">Fatura</p>
                <p class="mb-0"><?= $billing === [] ? '<span class="text-muted">—</span>' : $addrLines($billing) ?></p>
            </div>
        </div>

        <!-- Webhook olayları -->
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-bold">Son Webhook Olayları (iyzico)</div>
            <?php $recentWebhooks = (new \App\Repositories\WebhookEventRepository())->recent(8); ?>
            <?php if ($recentWebhooks === []): ?>
                <div class="card-body small text-muted">Kayıt yok.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentWebhooks as $wh): ?>
                        <li class="list-group-item small d-flex justify-content-between">
                            <span><?= e((string) ($wh['event_type'] ?? '')) ?></span>
                            <span class="badge text-bg-<?= (int) ($wh['signature_valid'] ?? 0) === 1 ? 'success' : 'danger' ?>"><?= e((string) ($wh['status'] ?? '')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
