<?php
/**
 * XML kaynağı ekleme / düzenleme formu.
 * Tam yol: app/Views/admin/feeds/form.php
 *
 * Değişkenler: $title, $feed (array|null), $suppliers, $categories, $error
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'feeds']); ?>
<?php
$feed = is_array($feed ?? null) ? $feed : null;
$isEdit = $feed !== null;
$feed = $feed ?? [];
$suppliers = is_array($suppliers ?? null) ? $suppliers : [];
$categories = is_array($categories ?? null) ? $categories : [];

$fval = static function (string $key, string $default = '') use ($feed, $isEdit): string {
    if ($isEdit && isset($feed[$key]) && (string) $feed[$key] !== '') {
        return (string) $feed[$key];
    }
    return $default;
};
$fchk = static function (string $key, bool $default = false) use ($feed, $isEdit): string {
    $on = $isEdit ? ((int) ($feed[$key] ?? 0)) === 1 : $default;
    return $on ? ' checked' : '';
};
$isSel = static function (mixed $current, mixed $option): string {
    return (string) $current === (string) $option ? ' selected' : '';
};
$action = $isEdit ? '/admin/feeds/' . (int) ($feed['id'] ?? 0) : '/admin/feeds';
$showPasswordHint = $isEdit && $fval('auth_type', 'none') === 'basic';
?>
<h1 class="h3 mb-4"><?= e($title ?? 'XML Kaynağı') ?></h1>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= e($action) ?>">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-12 col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-body p-4">
                    <h2 class="h5 card-title mb-4">Temel Bilgiler</h2>
                    <div class="mb-3">
                        <label for="name" class="form-label">Kaynak Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?= e($fval('name')) ?>" required maxlength="150">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="supplier_id" class="form-label">Tedarikçi <span class="text-danger">*</span></label>
                            <select class="form-select" id="supplier_id" name="supplier_id" required>
                                <option value="">— tedarikçi seçin —</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= e($supplier['id'] ?? '') ?>"<?= $isSel($fval('supplier_id'), $supplier['id'] ?? '') ?>><?= e($supplier['name'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="source_type" class="form-label">Kaynak Tipi</label>
                            <select class="form-select" id="source_type" name="source_type">
                                <option value="upload"<?= $isSel($fval('source_type', 'upload'), 'upload') ?>>Dosya Yükleme</option>
                                <option value="url"<?= $isSel($fval('source_type', 'upload'), 'url') ?>>HTTP/HTTPS URL</option>
                            </select>
                            <div class="form-text">URL seçilirse kaynak adresi (source_url) zorunludur.</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="source_url" class="form-label">Kaynak URL</label>
                        <input type="url" class="form-control" id="source_url" name="source_url"
                               value="<?= e($fval('source_url')) ?>" maxlength="1000"
                               placeholder="https://tedarikci.example/urunler.xml">
                        <div class="form-text">
                            Yalnız http/https, 80/443 portları; iç/ağ IP'lerine izin verilmez (SSRF koruması).
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="auth_type" class="form-label">Kimlik Doğrulama</label>
                            <select class="form-select" id="auth_type" name="auth_type">
                                <option value="none"<?= $isSel($fval('auth_type', 'none'), 'none') ?>>Kimlik doğrulama yok</option>
                                <option value="basic"<?= $isSel($fval('auth_type', 'none'), 'basic') ?>>HTTP Basic</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="auth_username" class="form-label">Kullanıcı Adı</label>
                            <input type="text" class="form-control" id="auth_username" name="auth_username"
                                   value="<?= e($fval('auth_username')) ?>" maxlength="120" autocomplete="off">
                        </div>
                        <div class="col-md-4">
                            <label for="auth_password" class="form-label">Şifre</label>
                            <input type="password" class="form-control" id="auth_password" name="auth_password"
                                   value="" maxlength="200" autocomplete="new-password">
                            <?php if ($showPasswordHint): ?>
                                <div class="form-text">Değiştirmek istemiyorsanız boş bırakın.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-body p-4">
                    <h2 class="h5 card-title mb-4">XML &amp; Senkron Ayarları</h2>
                    <div class="mb-3">
                        <label for="product_node" class="form-label">Ürün Düğümü <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="product_node" name="product_node"
                               value="<?= e($fval('product_node', 'product')) ?>" required maxlength="100">
                        <div class="form-text">Ürün düğümünün XML etiket adı, örn. product / urun / item.</div>
                    </div>
                    <div class="row g-3 mb-1">
                        <div class="col-6">
                            <label for="encoding" class="form-label">Karakter Kodlaması</label>
                            <select class="form-select" id="encoding" name="encoding">
                                <option value="UTF-8"<?= $isSel($fval('encoding', 'UTF-8'), 'UTF-8') ?>>UTF-8</option>
                                <option value="ISO-8859-9"<?= $isSel($fval('encoding', 'UTF-8'), 'ISO-8859-9') ?>>ISO-8859-9</option>
                                <option value="Windows-1254"<?= $isSel($fval('encoding', 'UTF-8'), 'Windows-1254') ?>>Windows-1254</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="sku_prefix" class="form-label">SKU Ön Eki</label>
                            <input type="text" class="form-control" id="sku_prefix" name="sku_prefix"
                                   value="<?= e($fval('sku_prefix')) ?>" maxlength="20">
                        </div>
                    </div>
                    <div class="form-text mb-3">SKU ön eki ürün SKU'larına eklenir; tedarikçiler arası çakışmayı önler, örn. TK-.</div>
                    <div class="mb-3">
                        <label for="default_category_id" class="form-label">Varsayılan Kategori</label>
                        <select class="form-select" id="default_category_id" name="default_category_id">
                            <option value="">— yok —</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e($category['id'] ?? '') ?>"<?= $isSel($fval('default_category_id'), $category['id'] ?? '') ?>><?= e($category['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label for="interval_minutes" class="form-label">Güncelleme Sıklığı (dk)</label>
                            <input type="number" class="form-control" id="interval_minutes" name="interval_minutes"
                                   value="<?= e($fval('interval_minutes', '60')) ?>" min="1" max="1440" required>
                        </div>
                        <div class="col-6">
                            <label for="max_file_size_mb" class="form-label">Maks. Dosya Boyutu (MB)</label>
                            <input type="number" class="form-control" id="max_file_size_mb" name="max_file_size_mb"
                                   value="<?= e($fval('max_file_size_mb', '100')) ?>" min="1" max="1024" required>
                        </div>
                    </div>
                    <div class="border-top pt-3">
                        <h3 class="h6">Güncelleme Davranışı</h3>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="update_description" name="update_description" value="1"<?= $fchk('update_description', true) ?>>
                            <label class="form-check-label" for="update_description">Açıklamaları güncelle</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="update_images" name="update_images" value="1"<?= $fchk('update_images', true) ?>>
                            <label class="form-check-label" for="update_images">Görselleri güncelle</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="update_price" name="update_price" value="1"<?= $fchk('update_price', true) ?>>
                            <label class="form-check-label" for="update_price">Fiyatları güncelle</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="update_stock" name="update_stock" value="1"<?= $fchk('update_stock', true) ?>>
                            <label class="form-check-label" for="update_stock">Stokları güncelle</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="auto_publish" name="auto_publish" value="1"<?= $fchk('auto_publish', false) ?>>
                            <label class="form-check-label" for="auto_publish">Aktarım sonrası ürünleri yayına al (aksi halde taslak)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="deactivate_missing" name="deactivate_missing" value="1"<?= $fchk('deactivate_missing', false) ?>>
                            <label class="form-check-label" for="deactivate_missing">XML'de görünmeyen ürünleri TAM başarılı aktarım sonrası pasife al</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"<?= $fchk('is_active', true) ?>>
                            <label class="form-check-label" for="is_active">Kaynak aktif</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-4">
        <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Değişiklikleri Kaydet' : 'Kaynağı Oluştur' ?></button>
        <a href="/admin/feeds" class="btn btn-outline-secondary">İptal</a>
    </div>
</form>

<script>
(function () {
    var sel = document.getElementById('source_type');
    var url = document.getElementById('source_url');
    function syncRequired() {
        if (!sel || !url) { return; }
        if (sel.value === 'url') { url.setAttribute('required', 'required'); }
        else { url.removeAttribute('required'); }
    }
    if (sel) {
        sel.addEventListener('change', syncRequired);
        syncRequired();
    }
})();
</script>
