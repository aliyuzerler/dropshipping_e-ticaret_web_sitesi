<?php
/**
 * Giriş ekranı. Tam yol: app/Views/store/auth/login.php
 * Beklenen: $errors (array<string, list<string>>, genelde boş — giriş hatası
 * flash 'error' ile _flash partial'ında gösterilir), eski girdiler old() ile.
 */
$errors = is_array($errors ?? null) ? $errors : [];
?>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="section-title h3 mb-1">Giriş Yap</h1>
                    <p class="text-muted small mb-4">Siparişlerinizi takip etmek ve hızlı alışveriş yapmak için hesabınıza giriş yapın.</p>

                    <form method="post" action="/giris" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="email">E-posta Adresi <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= e(old('email')) ?>" maxlength="180"
                                   autocomplete="email" required autofocus
                                   aria-describedby="emailError">
                            <div id="emailError">
                                <?php foreach (($errors['email'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label" for="password">Şifre <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="password" class="form-control" id="password" name="password"
                                   autocomplete="current-password" required aria-describedby="passwordError">
                            <div id="passwordError">
                                <?php foreach (($errors['password'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-brand w-100">Giriş Yap</button>
                    </form>

                    <hr>
                    <div class="d-flex flex-wrap justify-content-between gap-2 small">
                        <a href="/sifremi-unuttum">Şifremi unuttum</a>
                        <span>Hesabınız yok mu? <a href="/kayit"><strong>Üye olun</strong></a></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
