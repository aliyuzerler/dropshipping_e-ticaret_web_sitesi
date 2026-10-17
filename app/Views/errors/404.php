<?php
/**
 * 404 sayfası.
 * Tam yol: app/Views/errors/404.php
 *
 * Değişkenler: $safeMessage (kaçışlı), $showDebug
 */
$content = <<<'HTML'
<div class="text-center py-5">
    <h1 class="display-1 fw-bold">404</h1>
    <p class="fs-5 text-body-secondary">Aradığınız sayfa bulunamadı.</p>
    <a href="/" class="btn btn-outline-secondary">Ana Sayfaya Dön</a>
</div>
HTML;

// ErrorHandler include eder; $safeMessage eğer hata mesajı verildiyse gösterilir
if (!empty($safeMessage) && $showDebug) {
    $content .= '<p class="text-center text-danger small">' . $safeMessage . '</p>';
}

echo $content;
