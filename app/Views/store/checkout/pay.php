<?php
/**
 * AŞAMA 4 — iyzico Checkout Form ödeme sayfası.
 * Tam yol: app/Views/store/checkout/pay.php
 *
 * Beklenen: $order, $items, $payment, $content (iyzico checkoutFormContent),
 * $pageUrl, $initError, $isMock, $failedPayments.
 *
 * GERÇEK MOD: $content iyzico HTTPS API'sinden dönen resmî form betiğidir;
 * doğrudan gömülür (iyzico JS'i #iyzipay-checkout-form elemetine bağlanır).
 * MOCK MOD: sandbox simülasyon paneli gösterilir — senaryo seçilip sunucu
 * tarafında callback ile AYNI kod yolu işletilir.
 */
use App\Services\Order\OrderStatus;

$order   = is_array($order ?? null) ? $order : [];
$items   = is_array($items ?? null) ? $items : [];
$payment = is_array($payment ?? null) ? $payment : [];
$content = is_string($content ?? null) ? $content : '';
$pageUrl = is_string($pageUrl ?? null) ? $pageUrl : '';
/** @var bool $isMock */
$isMock  = (bool) ($isMock ?? false);
/** @var list<array<string,mixed>> $failedPayments */
$failedPayments = is_array($failedPayments ?? null) ? $failedPayments : [];
$payable = in_array((string) ($order['status'] ?? ''), OrderStatus::payable(), true);
$hasActive = (string) ($payment['status'] ?? '') === 'initiated' && (string) ($payment['token'] ?? '') !== '';
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item"><a href="/hesabim/siparisler">Siparişlerim</a></li>
            <li class="breadcrumb-item active" aria-current="page">Ödeme</li>
        </ol>
    </nav>

    <h1 class="section-title mb-1">Ödeme — <?= e((string) ($order['order_number'] ?? '')) ?></h1>
    <p class="small text-muted">
        Sipariş durumu: <span class="badge text-bg-<?= e(order_status_badge($order['status'] ?? '')) ?>"><?= e(order_status_label($order['status'] ?? '')) ?></span>
        · Ödenen tutar: <strong><?= money($order['grand_total'] ?? 0) ?></strong>
    </p>

    <?php if (!$payable): ?>
        <div class="alert alert-warning">Bu sipariş için ödeme başlatılamaz. <a href="/hesabim/siparisler/<?= rawurlencode((string) ($order['order_number'] ?? '')) ?>">Siparişe dön</a></div>
    <?php else: ?>
        <?php if ($initError !== null && $initError !== ''): ?>
            <div class="alert alert-danger" role="alert">
                ⚠️ <?= e($initError) ?>
                <a class="btn btn-outline-primary btn-sm ms-2" href="/odeme/onay/<?= rawurlencode((string) ($order['order_number'] ?? '')) ?>">Tekrar dene</a>
            </div>
        <?php endif; ?>

        <?php if ($failedPayments !== []): ?>
            <div class="alert alert-light border small text-muted">
                Bu sipariş için <?= count($failedPayments) ?> başarısız/başlatılamayan ödeme denemesi kayıtlı; kart verisi SAKLANMAMAKTADIR.
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-lg-8">
                <?php if ($isMock): ?>
                    <!-- ============ SANDBOX SİMÜLASYON (IYZICO_MODE=mock) ============ -->
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-header bg-warning-subtle fw-bold">🧪 iyzico Sandbox Simülasyonu (mock mod)</div>
                        <div class="card-body">
                            <?php if ($hasActive): ?>
                                <p class="small text-muted">
                                    Gerçek modda bu alanda iyzico Checkout Form (kart formu) render edilir ve ödeme
                                    tamamlandığında iyzico tarayıcıyı <code>POST /odeme/callback</code> adresine token ile
                                    yönlendirir. Mock modda senaryo seçin; ödeme sonucu <strong>sunucu tarafından
                                    sorgulanıp</strong> aynı idempotent işleme hattından geçirilir.
                                </p>
                                <form method="post" action="/odeme/simulasyon" class="d-flex flex-wrap gap-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="token" value="<?= e((string) ($payment['token'] ?? '')) ?>">
                                    <button type="submit" name="outcome" value="success" class="btn btn-success">✅ Ödemeyi Başarılı Tamamla</button>
                                    <button type="submit" name="outcome" value="failure" class="btn btn-outline-danger">❌ Ödemeyi Başarısız Kıl</button>
                                </form>
                            <?php else: ?>
                                <p class="text-muted small mb-0">
                                    Ödeme formu henüz başlatılamadı. Sayfayı yenileyin veya
                                    <a href="/odeme/onay/<?= rawurlencode((string) ($order['order_number'] ?? '')) ?>">tekrar deneyin</a>.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- ============ /SANDBOX SİMÜLASYON ============ -->
                <?php elseif ($hasActive && $content !== ''): ?>
                    <!-- ============ GERÇEK iyzico CHECKOUT FORM ============ -->
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-header bg-white fw-bold">🔒 Güvenli Ödeme — iyzico</div>
                        <div class="card-body p-3">
                            <!-- İçerik iyzico API'sinden (HTTPS) resmî checkoutFormContent olarak gelir -->
                            <div id="iyzipay-checkout-form" class="iyzipay-checkout-form"></div>
                            <?= $content /* iyzico resmî form betiği (kaynak: iyzico API üzerinden TLS) */ ?>
                        </div>
                    </div>
                    <!-- ============ /GERÇEK iyzico CHECKOUT FORM ============ -->
                <?php elseif ($pageUrl !== ''): ?>
                    <div class="alert alert-info">
                        Ödeme sayfasına yönlendiriliyorsunuz… <a class="btn btn-primary btn-sm ms-2" href="<?= e($pageUrl) ?>" rel="nofollow noopener">Ödeme Sayfasını Aç</a>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        Ödeme formu hazır değil.
                        <a class="btn btn-outline-primary btn-sm ms-2" href="/odeme/onay/<?= rawurlencode((string) ($order['order_number'] ?? '')) ?>">Yeniden dene</a>
                    </div>
                <?php endif; ?>

                <p class="small text-muted">
                    Kart bilgileriniz mağaza sunucularımızda <strong>tutulmaz</strong>; doğrudan iyzico güvenli ödeme
                    altyapısına girilir. Ödeme sonucu sunucu tarafında iyzico'dan sorgulanarak doğrulanır.
                </p>
            </div>

            <div class="col-12 col-lg-4">
                <div class="summary-card p-3 bg-white">
                    <h2 class="h5 mb-3">Sipariş Özeti</h2>
                    <?php foreach ($items as $item): ?>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="me-2"><?= e(mb_substr((string) ($item['product_name_snapshot'] ?? ''), 0, 38, 'UTF-8')) ?> × <?= (int) ($item['quantity'] ?? 0) ?></span>
                            <span class="text-nowrap"><?= money($item['line_total'] ?? 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ((float) ($order['discount_total'] ?? 0) > 0): ?>
                        <div class="d-flex justify-content-between small text-success"><span>İndirim</span><span>−<?= money($order['discount_total'] ?? 0) ?></span></div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between small"><span>Kargo</span><span><?= (float) ($order['shipping_total'] ?? 0) > 0 ? money($order['shipping_total'] ?? 0) : 'Ücretsiz' ?></span></div>
                    <hr>
                    <div class="summary-row summary-total"><span>Toplam</span><span><?= money($order['grand_total'] ?? 0) ?></span></div>
                    <a class="btn btn-link btn-sm px-0 mt-2" href="/hesabim/siparisler/<?= rawurlencode((string) ($order['order_number'] ?? '')) ?>">Sipariş detayına dön</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
