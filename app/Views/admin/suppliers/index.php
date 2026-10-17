<?php
/**
 * Tedarikçi listesi + ekleme/düzenleme formu.
 * Tam yol: app/Views/admin/suppliers/index.php
 *
 * Değişkenler: $title, $suppliers, $editId (int|null), $message, $error
 */
$editing = null;
foreach (($suppliers ?? []) as $s) {
    if ($editId !== null && (int) $s['id'] === (int) $editId) {
        $editing = $s;
        break;
    }
}
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'suppliers']); ?>

<h1 class="h3 mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span>Tedarikçiler</span>
    <span class="text-body-secondary small">Dropshipping kaynakları — XML aktarımı buradan başlar</span>
</h1>

<?php if (!empty($message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?= e($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Kapat"></button></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body p-4">
                <h2 class="h5 card-title mb-3"><?= $editing !== null ? 'Tedarikçiyi Düzenle #' . e($editing['id']) : 'Yeni Tedarikçi' ?></h2>
                <form method="post" action="<?= $editing !== null ? '/admin/suppliers/' . (int) $editing['id'] : '/admin/suppliers' ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="s_name" class="form-label">Ad <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="s_name" name="name" maxlength="120" required
                               value="<?= e($editing['name'] ?? '') ?>" placeholder="örn. Kırtasiye Merkez Depo">
                    </div>
                    <div class="mb-3">
                        <label for="s_code" class="form-label">Kod <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="s_code" name="code" maxlength="40" required
                               value="<?= e($editing['code'] ?? '') ?>" placeholder="örn. KMD" pattern="[A-Za-z0-9_\-]{2,40}">
                        <div class="form-text">Büyük harf/rakam/-/_ · SKU öneklerinde kullanılabilir.</div>
                    </div>
                    <div class="mb-3">
                        <label for="s_contact" class="form-label">Yetkili Kişi</label>
                        <input type="text" class="form-control" id="s_contact" name="contact_name" maxlength="120"
                               value="<?= e($editing['contact_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="s_email" class="form-label">E-posta</label>
                        <input type="email" class="form-control" id="s_email" name="email" maxlength="180"
                               value="<?= e($editing['email'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="s_phone" class="form-label">Telefon</label>
                        <input type="text" class="form-control" id="s_phone" name="phone" maxlength="40"
                               value="<?= e($editing['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label for="s_notes" class="form-label">Notlar</label>
                        <textarea class="form-control" id="s_notes" name="notes" rows="2"><?= e($editing['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="s_active" name="is_active"
                               <?= ($editing === null || (int) ($editing['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="s_active">Aktif</label>
                    </div>

                    <hr class="my-3">
                    <h3 class="h6 text-uppercase text-body-secondary">Sipariş Entegrasyonu <span class="text-lowercase fw-normal">(Aşama 5)</span></h3>
                    <div class="mb-3">
                        <label for="s_integration" class="form-label">Entegrasyon Tipi</label>
                        <select class="form-select" id="s_integration" name="integration_type">
                            <option value="manual" <?= ($editing['integration_type'] ?? 'manual') === 'manual' ? 'selected' : '' ?>>Manuel işleme (API yok — panelde görev)</option>
                            <option value="api" <?= ($editing['integration_type'] ?? '') === 'api' ? 'selected' : '' ?>>Tedarikçi API'si (gerçek uç bilgisi varsa)</option>
                        </select>
                        <div class="form-text">API bilgisi olmayan tedarikçi için otomatik entegrasyon üretilmez; siparişler manuel iletilir.</div>
                    </div>
                    <div class="mb-3">
                        <label for="s_api_endpoint" class="form-label">API Endpoint</label>
                        <input type="url" class="form-control" id="s_api_endpoint" name="api_endpoint" maxlength="255"
                               value="<?= e($editing['api_endpoint'] ?? '') ?>" placeholder="https://api.tedarikci.com/orders">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="s_api_auth" class="form-label">Kimlik Doğrulama</label>
                            <select class="form-select" id="s_api_auth" name="api_auth">
                                <?php foreach (['none' => 'Yok', 'bearer' => 'Bearer', 'basic' => 'Basic', 'header' => 'Özel Başlık'] as $authKey => $authLabel): ?>
                                    <option value="<?= e($authKey) ?>" <?= ($editing['api_auth'] ?? 'none') === $authKey ? 'selected' : '' ?>><?= e($authLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="s_api_header" class="form-label">Başlık Adı (özel)</label>
                            <input type="text" class="form-control" id="s_api_header" name="api_header_name" maxlength="60"
                                   placeholder="X-API-Key" value="X-API-Key">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label for="s_api_key" class="form-label">API Anahtarı</label>
                            <input type="password" class="form-control" id="s_api_key" name="api_key" maxlength="255" autocomplete="off">
                        </div>
                        <div class="col-6">
                            <label for="s_api_secret" class="form-label">API Parolası</label>
                            <input type="password" class="form-control" id="s_api_secret" name="api_secret" maxlength="255" autocomplete="off">
                        </div>
                    </div>
                    <p class="form-text small mb-3">
                        Anahtar/parola APP_KEY ile şifrelenir (AES-256-GCM), asla düz metin saklanmaz ve tekrar gösterilmez.
                        <?= !empty($editing['api_credentials_enc']) ? '<span class="badge text-bg-success">Kimlik bilgisi kayıtlı</span>' : '<span class="badge text-bg-secondary">Kimlik bilgisi yok</span>' ?>
                    </p>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><?= $editing !== null ? 'Güncelle' : 'Tedarikçi Ekle' ?></button>
                        <?php if ($editing !== null): ?>
                            <a href="/admin/suppliers" class="btn btn-outline-secondary">Vazgeç</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Kayıtlı Tedarikçiler</h2></div>
            <div class="card-body p-0">
                <?php if (($suppliers ?? []) === []): ?>
                    <p class="text-body-secondary p-4 mb-0">Henüz tedarikçi yok. Soldaki formdan ilk tedarikçiyi ekleyin.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 32rem; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Ad</th>
                                    <th scope="col">Kod</th>
                                    <th scope="col">İletişim</th>
                                    <th scope="col" class="text-center">XML Kaynağı</th>
                                    <th scope="col" class="text-center">Ürün</th>
                                    <th scope="col">Durum</th>
                                    <th scope="col" class="text-end">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suppliers as $s): ?>
                                    <tr>
                                        <td><?= e($s['id']) ?></td>
                                        <td class="fw-semibold"><?= e($s['name']) ?></td>
                                        <td><span class="badge text-bg-light border"><?= e($s['code']) ?></span></td>
                                        <td class="small text-body-secondary">
                                            <?= e($s['email'] ?? $s['phone'] ?? $s['contact_name'] ?? '—') ?>
                                        </td>
                                        <td class="text-center"><?= e($s['feed_count'] ?? 0) ?></td>
                                        <td class="text-center"><?= e($s['product_count'] ?? 0) ?></td>
                                        <td>
                                            <?php if ((int) $s['is_active'] === 1): ?>
                                                <span class="badge text-bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Pasif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm" role="group" aria-label="Tedarikçi işlemleri">
                                                <a class="btn btn-outline-secondary" href="/admin/suppliers?edit=<?= e($s['id']) ?>">Düzenle</a>
                                                <form method="post" action="/admin/suppliers/<?= e($s['id']) ?>/toggle" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-outline-warning"><?= ((int) $s['is_active']) === 1 ? 'Pasife Al' : 'Aktifleştir' ?></button>
                                                </form>
                                                <form method="post" action="/admin/suppliers/<?= e($s['id']) ?>/delete" class="d-inline" data-confirm="Tedarikçi silinsin mi? Bağlı XML kaynağı/ürün verisi varsa silme reddedilir.">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-outline-danger">Sil</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
