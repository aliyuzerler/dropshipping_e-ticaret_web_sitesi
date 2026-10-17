<?php
/**
 * Mağaza alt bilgisi — sticky footer (mt-auto) + güven/yardım bağlantıları.
 * Tam yol: app/Views/store/partials/_footer.php
 *
 * Beklenen: $storeName, $settings
 */
?>
<footer class="store-footer mt-auto text-white pt-4 pb-3">
    <div class="container">
        <div class="row g-4">
            <div class="col-12 col-md-4">
                <h2 class="h5 fw-bold">🛒 <?= e($storeName) ?></h2>
                <p class="small mb-1"><?= e($settings->companyTitle()) ?></p>
                <p class="small mb-1"><?= e($settings->contactAddress()) ?></p>
                <p class="small mb-0">
                    <a class="footer-link" href="mailto:<?= e($settings->contactEmail()) ?>"><?= e($settings->contactEmail()) ?></a>
                    · <span><?= e($settings->contactPhone()) ?></span>
                </p>
            </div>
            <div class="col-6 col-md-2">
                <h2 class="h6 fw-bold">Kurumsal</h2>
                <ul class="list-unstyled footer-list small mb-0">
                    <li><a class="footer-link" href="/iletisim">İletişim</a></li>
                    <li><a class="footer-link" href="/gizlilik-ve-kvkk">Gizlilik &amp; KVKK</a></li>
                    <li><a class="footer-link" href="/mesafeli-satis-sozlesmesi">Mesafeli Satış Sözleşmesi</a></li>
                </ul>
            </div>
            <div class="col-6 col-md-3">
                <h2 class="h6 fw-bold">Yardım</h2>
                <ul class="list-unstyled footer-list small mb-0">
                    <li><a class="footer-link" href="/on-bilgilendirme-formu">Ön Bilgilendirme Formu</a></li>
                    <li><a class="footer-link" href="/iade-ve-teslimat">İade ve Teslimat</a></li>
                    <li><a class="footer-link" href="/sifremi-unuttum">Şifremi Unuttum</a></li>
                </ul>
            </div>
            <div class="col-12 col-md-3">
                <h2 class="h6 fw-bold">Güvenli Alışveriş</h2>
                <p class="small mb-2">Tüm fiyatlar KDV dahildir. Siparişleriniz anlaşmalı kargo firmaları ile gönderilir.</p>
                <p class="small mb-0 text-warning">
                    ℹ️ Ödeme altyapısı (iyzico) bir sonraki aşamada devreye alınacaktır; şu anda gerçek ödeme alımı yapılmamaktadır.
                </p>
            </div>
        </div>
        <hr class="border-secondary mt-4 mb-2">
        <div class="d-flex flex-wrap justify-content-between gap-2 small">
            <span>&copy; <?= e(date('Y')) ?> <?= e($storeName) ?> — Tüm hakları saklıdır.</span>
            <span class="opacity-75">PHP <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?> · MySQL · XML Aktarım Motoru</span>
        </div>
    </div>
</footer>
