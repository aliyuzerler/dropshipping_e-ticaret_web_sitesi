<?php
/**
 * Adres formu — create ve edit ORTAK şablon.
 * Tam yol: app/Views/store/account/address_form.php
 * Beklenen: $address? (edit modunda mevcut satır; create'te null),
 * $errors (array<string, list<string>>).
 * Action: create → POST /hesabim/adresler, edit → POST /hesabim/adresler/{id}
 */
$address = is_array($address ?? null) ? $address : null;
$errors  = is_array($errors ?? null) ? $errors : [];
$isEdit  = $address !== null;
$action  = $isEdit ? '/hesabim/adresler/' . (int) $address['id'] : '/hesabim/adresler';

/** Alan değeri: flash'lenmiş eski girdi öncelikli (boş string dahil), yoksa mevcut kayıt.
 * AŞAMA 6: çıktı ÇAĞRI NOKTASI değil KAPANIŞ içinde e() ile kaçışlı — eski girdi
 * kullanıcı POST verisi olduğundan reflected XSS vektörüydü. */
$val = static function (string $key, string $default = '') use ($address): string {
    $old = old($key, null);
    if ($old !== null) {
        return e((string) $old);
    }
    return $address !== null ? e((string) ($address[$key] ?? $default)) : $default;
};
/** Checkbox: POST'ta geldi ise onu, yoksa kayıt değerini kullan. */
$oldDefault = old('is_default', null);
$isChecked  = $oldDefault !== null ? (int) $oldDefault === 1 : ($isEdit && (int) ($address['is_default'] ?? 0) === 1);
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item"><a href="/hesabim">Hesabım</a></li>
            <li class="breadcrumb-item"><a href="/hesabim/adresler">Adreslerim</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $isEdit ? 'Adresi Düzenle' : 'Yeni Adres' ?></li>
        </ol>
    </nav>

    <h1 class="section-title mb-4"><?= $isEdit ? 'Adresi Düzenle' : 'Yeni Adres' ?></h1>

    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form method="post" action="<?= e($action) ?>" novalidate>
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="title">Adres Başlığı</label>
                                <input type="text" class="form-control" id="title" name="title"
                                       value="<?= $val('title') ?>" maxlength="80"
                                       placeholder="Örn: Ev, Ofis" aria-describedby="titleError">
                                <div id="titleError">
                                    <?php foreach (($errors['title'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="full_name">Ad Soyad <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name"
                                       value="<?= $val('full_name') ?>" maxlength="160"
                                       autocomplete="name" required aria-describedby="fullNameError">
                                <div id="fullNameError">
                                    <?php foreach (($errors['full_name'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="country_code">Ülke</label>
                                <input type="text" class="form-control" id="country_code" name="country_code"
                                       value="TR" maxlength="2" readonly aria-readonly="true">
                                <div class="form-text">Şu anda yalnızca Türkiye içi teslimat yapılmaktadır.</div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="phone">Telefon</label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                       value="<?= $val('phone') ?>" maxlength="40"
                                       autocomplete="tel" aria-describedby="phoneError">
                                <div id="phoneError">
                                    <?php foreach (($errors['phone'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="city">İl <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control" id="city" name="city"
                                       value="<?= $val('city') ?>" maxlength="80" required aria-describedby="cityError">
                                <div id="cityError">
                                    <?php foreach (($errors['city'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="district">İlçe <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control" id="district" name="district"
                                       value="<?= $val('district') ?>" maxlength="80" required aria-describedby="districtError">
                                <div id="districtError">
                                    <?php foreach (($errors['district'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="postal_code">Posta Kodu</label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code"
                                       value="<?= $val('postal_code') ?>" maxlength="16"
                                       autocomplete="postal-code" inputmode="numeric" aria-describedby="postalCodeError">
                                <div id="postalCodeError">
                                    <?php foreach (($errors['postal_code'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="line1">Açık Adres <span class="text-danger" aria-hidden="true">*</span></label>
                                <textarea class="form-control" id="line1" name="line1" rows="2"
                                          maxlength="255" required aria-describedby="line1Error"><?= $val('line1') ?></textarea>
                                <div id="line1Error">
                                    <?php foreach (($errors['line1'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="line2">Açık Adres 2 (opsiyonel)</label>
                                <textarea class="form-control" id="line2" name="line2" rows="2"
                                          maxlength="255" aria-describedby="line2Error"><?= $val('line2') ?></textarea>
                                <div id="line2Error">
                                    <?php foreach (($errors['line2'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_default" name="is_default" value="1"
                                           <?= $isChecked ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_default">Varsayılan teslimat adresim olsun</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="submit" class="btn btn-brand px-4"><?= $isEdit ? 'Değişiklikleri Kaydet' : 'Adresi Kaydet' ?></button>
                            <a class="btn btn-outline-secondary" href="/hesabim/adresler">İptal</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
