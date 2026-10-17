<?php
/**
 * Admin giriş formu.
 * Tam yol: app/Views/admin/login.php
 *
 * Değişkenler: $title, $error, $email
 */
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Yönetici Girişi</h1>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="post" action="/admin/login" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="email" class="form-label">E-posta</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?= e($email ?? '') ?>" required maxlength="180" autocomplete="username">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Şifre</label>
                        <input type="password" class="form-control" id="password" name="password"
                               required minlength="8" maxlength="200" autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-dark w-100">Giriş Yap</button>
                </form>

                <p class="text-body-secondary small mt-3 mb-0">
                    5 başarısız denemeden sonra hesap 15 dakika kilitlenir. Tüm girişler audit log'a yazılır.
                </p>
            </div>
        </div>
    </div>
</div>
