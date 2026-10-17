<?php
/**
 * Şifremi unuttum formu. Tam yol: app/Views/store/auth/forgot.php
 * Yanıt her zaman aynıdır (e-posta varlığı ifşa edilmez); APP_ENV=local'da
 * sandbox bağlantısı flash 'info' ile gösterilir.
 */
?>
<div class="container py-4 py-md-5">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-8 col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h1 class="section-title h3 mb-1">Şifremi Unuttum</h1>
                    <p class="text-muted small mb-4">
                        Kayıtlı e-posta adresinizi girin; şifrenizi sıfırlamanız için size bir bağlantı gönderelim.
                        Bağlantı 1 saat geçerlidir ve tek kullanımlıktır.
                    </p>

                    <form method="post" action="/sifremi-unuttum" novalidate>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="email">E-posta Adresi <span class="text-danger" aria-hidden="true">*</span></label>
                            <input type="email" class="form-control" id="email" name="email"
                                   value="<?= e(old('email')) ?>" maxlength="180"
                                   autocomplete="email" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-brand w-100">Sıfırlama Bağlantısı Gönder</button>
                    </form>

                    <hr>
                    <p class="small text-center mb-0">
                        Şifrenizi hatırladınız mı? <a href="/giris"><strong>Giriş yapın</strong></a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
