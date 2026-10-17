<?php
/**
 * Üyelik kayıt formu. Tam yol: app/Views/store/auth/register.php
 * Beklenen: $errors (array<string, list<string>>), eski girdiler old() ile.
 * Doğrulama hatasında controller tek istekte bu view'ı render eder.
 */
$errors = is_array($errors ?? null) ? $errors : [];
/** Alan hatası + eski değer birleşik çıktısı için küçük yerel yardımcı. */
$oldValue = static fn (string $key): string => e(old($key));
?>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-9 col-lg-6">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="section-title h3 mb-1">Üye Ol</h1>
                    <p class="text-muted small mb-4">Ücretsiz üyelik ile sipariş takibi, adres defteri ve hızlı ödeme avantajlarından yararlanın.</p>

                    <form method="post" action="/kayit" novalidate>
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="first_name">Ad <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control" id="first_name" name="first_name"
                                       value="<?= $oldValue('first_name') ?>" maxlength="80"
                                       autocomplete="given-name" required aria-describedby="firstNameError">
                                <div id="firstNameError">
                                    <?php foreach (($errors['first_name'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="last_name">Soyad <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control" id="last_name" name="last_name"
                                       value="<?= $oldValue('last_name') ?>" maxlength="80"
                                       autocomplete="family-name" required aria-describedby="lastNameError">
                                <div id="lastNameError">
                                    <?php foreach (($errors['last_name'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="email">E-posta Adresi <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?= $oldValue('email') ?>" maxlength="180"
                                       autocomplete="email" required aria-describedby="emailError">
                                <div id="emailError">
                                    <?php foreach (($errors['email'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="phone">Telefon (opsiyonel)</label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                       value="<?= $oldValue('phone') ?>" maxlength="40"
                                       autocomplete="tel" aria-describedby="phoneError">
                                <div id="phoneError">
                                    <?php foreach (($errors['phone'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="password">Şifre <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="password" class="form-control" id="password" name="password"
                                       minlength="8" autocomplete="new-password" required aria-describedby="passwordError">
                                <div id="passwordError">
                                    <?php foreach (($errors['password'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="password_confirmation">Şifre (Tekrar) <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                                       minlength="8" autocomplete="new-password" required aria-describedby="passwordConfirmationError">
                                <div id="passwordConfirmationError">
                                    <?php foreach (($errors['password_confirmation'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="small text-muted mt-3">
                            En az 8 karakterlik bir şifre belirleyin.
                        </div>

                        <button type="submit" class="btn btn-brand w-100 mt-3">Hesap Oluştur</button>

                        <p class="small text-muted text-center mt-3 mb-0">
                            Üye olarak <a href="/gizlilik-ve-kvkk" target="_blank" rel="noopener">Gizlilik &amp; KVKK metnini</a> kabul edersiniz.
                        </p>
                    </form>

                    <hr>
                    <p class="small text-center mb-0">
                        Zaten üye misiniz? <a href="/giris"><strong>Giriş yapın</strong></a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
