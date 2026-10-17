<?php
/**
 * Flash mesajları + doğrulama hataları. Tam yol: app/Views/store/partials/_flash.php
 * Hata listesi $validationErrors (list<string>) ile de beslenebilir.
 */
$success = get_flash('success');
$error = get_flash('error');
$info = get_flash('info');
/** @var list<string> $validationErrors */
$validationErrors = $validationErrors ?? [];
?>
<?php if ($success): ?>
    <div class="container mt-3">
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            ✔ <?= e($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
        </div>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="container mt-3">
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ✖ <?= e($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
        </div>
    </div>
<?php endif; ?>
<?php if ($info): ?>
    <div class="container mt-3">
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            ℹ️ <?= e($info) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button>
        </div>
    </div>
<?php endif; ?>
<?php if ($validationErrors !== []): ?>
    <div class="container mt-3">
        <div class="alert alert-danger" role="alert">
            <strong>Lütfen aşağıdaki hataları düzeltin:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($validationErrors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
<?php endif; ?>
