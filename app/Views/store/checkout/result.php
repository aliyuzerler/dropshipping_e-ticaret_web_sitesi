<?php
/**
 * AŞAMA 4 — Ödeme sonuç sayfası.
 * Tam yol: app/Views/store/checkout/result.php
 *
 * Beklenen: $order, $payment, $refunds, $recovered (bool — bu sayfada kurtarma
 * sorgusu yapıldı mı). Başarı yalnızca sunucu tarafı doğrulaması (payments.status)
 * üzerinden gösterilir; tarayıcı mesajı tek başına kanıt değildir.
 */
use App\Services\Order\OrderStatus;

$order   = is_array($order ?? null) ? $order : [];
$payment = is_array($payment ?? null) ? $payment : [];
/** @var list<array<string,mixed>> $refunds */
$refunds = is_array($refunds ?? null) ? $refunds : [];
/** @var bool $recovered */
$recovered = (bool) ($recovered ?? false);

$status      = (string) ($order['status'] ?? '');
$payStatus   = (string) ($payment['status'] ?? '');
$number      = (string) ($order['order_number'] ?? '');
?>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-7">
            <?php if ($status === OrderStatus::PAID || $payStatus === 'success'): ?>
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body p-4">
                        <div style="font-size:3rem" aria-hidden="true">✅</div>
                        <h1 class="h3 mb-2">Ödemeniz Onaylandı</h1>
                        <p class="text-muted mb-1">
                            <strong><?= e($number) ?></strong> numaralı siparişiniz için <?= money($payment['amount'] ?? ($order['grand_total'] ?? 0)) ?> tutarında
                            ödemeniz iyzico üzerinden alındı.
                        </p>
                        <?php if ((string) ($payment['card_last_four'] ?? '') !== ''): ?>
                            <p class="small text-muted mb-1">Kart: **** <?= e((string) $payment['card_last_four']) ?><?= (string) ($payment['card_association'] ?? '') !== '' ? ' · ' . e((string) $payment['card_association']) : '' ?></p>
                        <?php endif; ?>
                        <?php if ($recovered): ?>
                            <p class="small text-info">Not: Ödeme sonucu bu sayfada sunucu tarafında yeniden doğrulandı.</p>
                        <?php endif; ?>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                            <a class="btn btn-primary" href="/hesabim/siparisler/<?= rawurlencode($number) ?>">Sipariş Detayı</a>
                            <a class="btn btn-outline-brand" href="/">Alışverişe Devam Et</a>
                        </div>
                    </div>
                </div>
            <?php elseif ($status === OrderStatus::PAYMENT_FAILED || $payStatus === 'failed'): ?>
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body p-4">
                        <div style="font-size:3rem" aria-hidden="true">❌</div>
                        <h1 class="h3 mb-2">Ödeme Başarısız</h1>
                        <p class="text-muted mb-1">Ödemeniz tamamlanamadı<?= (string) ($payment['error_message'] ?? '') !== '' ? ': ' . e((string) $payment['error_message']) : '.' ?></p>
                        <p class="small text-muted">Siparişiniz kayıtlı; kart bilgisi saklanmadı. Farklı bir kartla tekrar deneyebilirsiniz.</p>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                            <a class="btn btn-primary" href="/odeme/onay/<?= rawurlencode($number) ?>">Ödemeyi Tekrar Dene</a>
                            <a class="btn btn-outline-brand" href="/hesabim/siparisler">Siparişlerim</a>
                        </div>
                    </div>
                </div>
            <?php elseif ($status === OrderStatus::CANCELLED): ?>
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body p-4">
                        <div style="font-size:3rem" aria-hidden="true">🚫</div>
                        <h1 class="h3 mb-2">Sipariş İptal Edildi</h1>
                        <p class="text-muted mb-3"><?= e($number) ?> numaralı sipariş iptal edildi. Başarılı ödemeniz varsa iade süreci başlatılır.</p>
                        <a class="btn btn-outline-brand" href="/hesabim/siparisler">Siparişlerim</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="card shadow-sm border-0 text-center">
                    <div class="card-body p-4">
                        <div style="font-size:3rem" aria-hidden="true">⏳</div>
                        <h1 class="h3 mb-2">Ödeme İşleniyor</h1>
                        <p class="text-muted mb-1">
                            Ödemenizin sonucu henüz doğrulanmadı. 3D Secure / banka onayı
                            sürebilir; bu sayfa sonuçla otomatik güncellenmez.
                        </p>
                        <p class="small text-muted">Birkaç dakika içinde kontrol edin; ödeme sonucu sunucuda iyzico'dan sorgulanarak işlenir.</p>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-3">
                            <a class="btn btn-primary" href="/odeme/sonuc/<?= rawurlencode($number) ?>">Sonucu Yenile</a>
                            <a class="btn btn-outline-brand" href="/hesabim/siparisler">Siparişlerim</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($refunds !== []): ?>
                <div class="card shadow-sm border-0 mt-3">
                    <div class="card-header bg-white fw-bold">İptal / İade Kayıtları</div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($refunds as $rf): ?>
                            <li class="list-group-item small d-flex justify-content-between">
                                <span><?= ($rf['type'] ?? '') === 'cancel' ? 'Ödeme iptali' : (($rf['scope'] ?? '') === 'partial' ? 'Kısmi iade' : 'Tam iade') ?> — <?= money($rf['amount'] ?? 0) ?></span>
                                <span class="badge text-bg-secondary"><?= e(refund_status_label($rf['status'] ?? '')) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
