<?php
/**
 * 500 sayfası.
 * Tam yol: app/Views/errors/500.php
 *
 * Değişkenler: $safeMessage (kaçışlı), $showDebug
 */
$content = <<<'HTML'
<div class="text-center py-5">
    <h1 class="display-1 fw-bold">500</h1>
    <p class="fs-5 text-body-secondary">Sunucu hatası oluştu. Sorun kayıt altına alındı, kısa sürede çözülecek.</p>
    <a href="/" class="btn btn-outline-secondary">Ana Sayfaya Dön</a>
</div>
HTML;

if (!empty($safeMessage) && $showDebug) {
    $content .= '<div class="mx-auto" style="max-width:720px"><div class="alert alert-danger small"><strong>Hata:</strong> ' . $safeMessage . '</div></div>';
}

echo $content;
