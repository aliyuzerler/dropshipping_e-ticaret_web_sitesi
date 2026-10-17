<?php
/**
 * Sayfalama partial — mevcut GET parametrelerini koruyarak page=N ekler.
 * Tam yol: app/Views/store/partials/_pagination.php
 *
 * Beklenen: $page (int), $lastPage (int), $baseUrl (string, sorgusuz path),
 *           $queryParams (array<string,mixed> korunacak GET parametreleri)
 * Tek sayfada hiçbir şey basmaz.
 */
$page = max(1, (int) ($page ?? 1));
$lastPage = max(1, (int) ($lastPage ?? 1));
$baseUrl = (string) ($baseUrl ?? '/');
$queryParams = is_array($queryParams ?? null) ? $queryParams : [];

if ($lastPage <= 1) {
    return;
}

/** Sayfa numarası → hedef URL (mevcut parametreler + page). */
$linkFor = static function (int $number) use ($baseUrl, $queryParams): string {
    $query = http_build_query(array_merge($queryParams, ['page' => $number]));
    return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . $query;
};

$windowStart = max(1, $page - 2);
$windowEnd = min($lastPage, $page + 2);
?>
<nav aria-label="Sayfalama" class="mt-4">
    <ul class="pagination justify-content-center flex-wrap mb-0">

        <!-- Önceki -->
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <?php if ($page > 1): ?>
                <a class="page-link" href="<?= e($linkFor($page - 1)) ?>" rel="prev" aria-label="Önceki sayfa">‹ Önceki</a>
            <?php else: ?>
                <span class="page-link" aria-hidden="true">‹ Önceki</span>
            <?php endif; ?>
        </li>

        <!-- İlk sayfa + sol kesme -->
        <?php if ($windowStart > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= e($linkFor(1)) ?>">1</a></li>
            <?php if ($windowStart > 2): ?>
                <li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Pencere (±2) -->
        <?php for ($number = $windowStart; $number <= $windowEnd; $number++): ?>
            <li class="page-item <?= $number === $page ? 'active' : '' ?>" <?= $number === $page ? 'aria-current="page"' : '' ?>>
                <?php if ($number === $page): ?>
                    <span class="page-link"><?= $number ?></span>
                <?php else: ?>
                    <a class="page-link" href="<?= e($linkFor($number)) ?>"><?= $number ?></a>
                <?php endif; ?>
            </li>
        <?php endfor; ?>

        <!-- Sağ kesme + son sayfa -->
        <?php if ($windowEnd < $lastPage): ?>
            <?php if ($windowEnd < $lastPage - 1): ?>
                <li class="page-item disabled"><span class="page-link" aria-hidden="true">…</span></li>
            <?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= e($linkFor($lastPage)) ?>"><?= $lastPage ?></a></li>
        <?php endif; ?>

        <!-- Sonraki -->
        <li class="page-item <?= $page >= $lastPage ? 'disabled' : '' ?>">
            <?php if ($page < $lastPage): ?>
                <a class="page-link" href="<?= e($linkFor($page + 1)) ?>" rel="next" aria-label="Sonraki sayfa">Sonraki ›</a>
            <?php else: ?>
                <span class="page-link" aria-hidden="true">Sonraki ›</span>
            <?php endif; ?>
        </li>

    </ul>
</nav>
