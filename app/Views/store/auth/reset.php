<?php
/**
 * Şifre sıfırlama formu (yeni şifre belirleme).
 * Tam yol: app/Views/store/auth/reset.php
 * Beklenen: $errors (array<string, list<string>>), $token (gizli alana taşınır),
 * eski girdiler old() ile (şifre alanları asla repopulate edilmez).
 */
$errors = is_array($errors ?? null) ? $errors : [];
$token  = (string) ($token ?? '');
$tokenErrors = $errors['token'] ?? [];
?>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="section-title h3 mb-1">Yeni Şifre Belirle</h1>
                    <p class="text-muted small mb-4">Hesabınız için yeni bir şifre oluşturun.</p>

                    <?php if ($tokenErrors !== []): ?>
                        <div class="alert alert-danger" role="alert">
                            ✖ <?php foreach ($tokenErrors as $m): ?><?= e($m) ?><?php endforeach; ?>
                            <hr>
                            <a class="alert-link" href="/sifremi-unuttum">Yeni sıfırlama bağlantısı talep edin.</a>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/sifre-sifirla" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">

                        <div class="mb-3">
                            <label class="form-label" for="password">Yeni Şifre <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="password" class="form-control" id="password" name="password"
                                   minlength="8" autocomplete="new-password" required aria-describedby="passwordHint passwordError">
                            <div id="passwordHint" class="form-text">
                                Güçlü şifre ipucu: en az 8 karakter kullanın; harf, rakam ve simge karışımı önerilir.
                            </div>
                            <div id="passwordError">
                                <?php foreach (($errors['password'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="password_confirmation">Yeni Şifre (Tekrar) <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                                   minlength="8" autocomplete="new-password" required aria-describedby="passwordConfirmationError">
                            <div id="passwordConfirmationError">
                                <?php foreach (($errors['password_confirmation'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-brand w-100" <?= $token === '' ? 'disabled' : '' ?>>Şifreyi Güncelle</button>
                    </form>

                    <hr>
                    <p class="small text-center mb-0">
                        <a href="/giris">Giriş sayfasına dön</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
