<?php
/**
 * İletişim sayfası — iletişim bilgileri kartı + mesaj formu.
 * Tam yol: app/Views/store/pages/contact.php
 *
 * Beklenen: $title, $settingsInfo {name,email,phone,address},
 *           $validationErrors (array<string,list<string>> alan bazlı, opsiyonel)
 */
/** @var array<string,list<string>> $validationErrors */
$validationErrors = is_array($validationErrors ?? null) ? $validationErrors : [];
$settingsInfo = is_array($settingsInfo ?? null) ? $settingsInfo : [];
$oldValue = static fn (string $key): string => (string) old($key);

/** Alanın hatalı olup olmadığı (is-invalid sınıfı için). */
$hasError = static fn (string $field): bool => ($validationErrors[$field] ?? []) !== [];
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item active" aria-current="page">İletişim</li>
        </ol>
    </nav>

    <h1 class="section-title mb-4">İletişim</h1>

    <div class="row g-4">

        <!-- İletişim bilgileri -->
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white fw-bold">İletişim Bilgileri</div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">
                        🏢 <strong><?= e((string) ($settingsInfo['name'] ?? '')) ?></strong>
                    </li>
                    <li class="list-group-item">
                        ✉️ <a href="mailto:<?= e((string) ($settingsInfo['email'] ?? '')) ?>"><?= e((string) ($settingsInfo['email'] ?? '')) ?></a>
                    </li>
                    <li class="list-group-item">
                        📞 <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) ($settingsInfo['phone'] ?? '')) ?: '') ?>"><?= e((string) ($settingsInfo['phone'] ?? '')) ?></a>
                    </li>
                    <li class="list-group-item">
                        📍 <?= e((string) ($settingsInfo['address'] ?? '')) ?>
                    </li>
                </ul>
                <div class="card-body small text-muted">
                    Form üzerinden ulaştığınız talepler 1-2 iş günü içinde yanıtlanır.
                    Sipariş durumunuz için <a href="/hesabim/siparisler">Siparişlerim</a> sayfasını kontrol edebilirsiniz.
                </div>
            </div>
        </div>

        <!-- Mesaj formu -->
        <div class="col-12 col-lg-7">
            <form method="post" action="/iletisim" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label" for="cName">Ad Soyad <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= $hasError('ad') ? 'is-invalid' : '' ?>" id="cName"
                           name="ad" value="<?= e($oldValue('ad')) ?>" maxlength="80" required
                           autocomplete="name" aria-describedby="cNameError">
                    <?php foreach ($validationErrors['ad'] ?? [] as $msg): ?>
                        <div class="text-danger small mt-1" id="cNameError"><?= e($msg) ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="cEmail">E-posta <span class="text-danger">*</span></label>
                    <input type="email" class="form-control <?= $hasError('email') ? 'is-invalid' : '' ?>" id="cEmail"
                           name="email" value="<?= e($oldValue('email')) ?>" maxlength="160" required
                           autocomplete="email" aria-describedby="cEmailError">
                    <?php foreach ($validationErrors['email'] ?? [] as $msg): ?>
                        <div class="text-danger small mt-1" id="cEmailError"><?= e($msg) ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="cSubject">Konu <span class="text-danger">*</span></label>
                    <input type="text" class="form-control <?= $hasError('konu') ? 'is-invalid' : '' ?>" id="cSubject"
                           name="konu" value="<?= e($oldValue('konu')) ?>" maxlength="120" required
                           aria-describedby="cSubjectError">
                    <?php foreach ($validationErrors['konu'] ?? [] as $msg): ?>
                        <div class="text-danger small mt-1" id="cSubjectError"><?= e($msg) ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="cMessage">Mesajınız <span class="text-danger">*</span></label>
                    <textarea class="form-control <?= $hasError('mesaj') ? 'is-invalid' : '' ?>" id="cMessage"
                              name="mesaj" rows="6" maxlength="2000" required
                              aria-describedby="cMessageError"><?= e($oldValue('mesaj')) ?></textarea>
                    <div class="form-text">10-2000 karakter.</div>
                    <?php foreach ($validationErrors['mesaj'] ?? [] as $msg): ?>
                        <div class="text-danger small mt-1" id="cMessageError"><?= e($msg) ?></div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn btn-brand px-4">✉️ Mesajı Gönder</button>
                <p class="small text-muted mt-2 mb-0">
                    Formu göndererek <a href="/gizlilik-ve-kvkk">Gizlilik ve KVKK</a> metnini kabul etmiş olursunuz.
                </p>
            </form>
        </div>
    </div>
</div>
