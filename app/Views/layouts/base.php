<?php
/**
 * Temel layout.
 * Tam yol: app/Views/layouts/base.php
 *
 * Beklenen değişkenler: $content, $title
 */
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'XML E-Ticaret') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="d-flex flex-column min-vh-100">
<header>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark" aria-label="Ana menü">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="/">XML E-Ticaret</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                    aria-controls="mainNav" aria-expanded="false" aria-label="Menüyü aç/kapat">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="/">Ana Sayfa</a></li>
                    <li class="nav-item"><a class="nav-link" href="/health" target="_blank" rel="noopener">Health</a></li>
                    <li class="nav-item"><a class="nav-link" href="/admin">Admin Panel</a></li>
                </ul>
            </div>
        </div>
    </nav>
</header>

<main class="flex-grow-1 py-4">
    <div class="container">
        <?= $content ?>
    </div>
</main>

<footer class="bg-light border-top py-3 mt-auto">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="text-muted small">&copy; <?= e(date('Y')) ?> XML E-Ticaret — Aşama 1 + 2 (Mimari, İskelet &amp; XML Aktarım Motoru)</span>
        <span class="text-muted small">PHP <?= e(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) ?> · MySQL · XMLReader</span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="/assets/js/app.js"></script>
</body>
</html>
