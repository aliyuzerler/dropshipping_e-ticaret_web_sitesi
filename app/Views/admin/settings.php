<?php
/**
 * Admin — Mağaza Ayarları (Aşama 3).
 * Tam yol: app/Views/admin/settings.php
 * Beklenen: $active, $values (SettingsService::all())
 */
$v = static fn (string $key): string => e($values[$key] ?? (string) config($key, ''));
?>
<?= View::partial('admin/_nav', ['active' => $active ?? 'settings']) ?>

<div class="row g-4">
    <div class="col-12 col-xl-8">
        <form method="post" action="/admin/settings">
            <?= csrf_field() ?>

            <div class="card mb-4">
                <div class="card-header bg-white fw-bold">Mağaza Bilgisi</div>
                <div class="card-body row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="store_name">Mağaza Adı</label>
                        <input class="form-control" id="store_name" name="store_name" value="<?= $v('store.name') ?>" maxlength="120" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="store_company_title">Ünvan (TASLAK)</label>
                        <input class="form-control" id="store_company_title" name="store_company_title" value="<?= $v('store.company_title') ?>" maxlength="190">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="store_contact_email">İletişim E-posta</label>
                        <input class="form-control" type="email" id="store_contact_email" name="store_contact_email" value="<?= $v('store.contact_email') ?>" maxlength="180">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="store_contact_phone">İletişim Telefon</label>
                        <input class="form-control" id="store_contact_phone" name="store_contact_phone" value="<?= $v('store.contact_phone') ?>" maxlength="40">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="store_contact_address">Adres</label>
                        <textarea class="form-control" id="store_contact_address" name="store_contact_address" rows="2" maxlength="500"><?= $v('store.contact_address') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-white fw-bold">Kargo</div>
                <div class="card-body row g-3">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="store_shipping_fee">Kargo Ücreti (₺)</label>
                        <input class="form-control" type="number" step="0.01" min="0" id="store_shipping_fee" name="store_shipping_fee" value="<?= $v('store.shipping_fee') ?>">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="store_free_shipping_threshold">Ücretsiz Kargo Eşiği (₺)</label>
                        <input class="form-control" type="number" step="0.01" min="0" id="store_free_shipping_threshold" name="store_free_shipping_threshold" value="<?= $v('store.free_shipping_threshold') ?>">
                        <div class="form-text">Bu tutar ve üzerindeki siparişlerde kargo ücretsizdir.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-white fw-bold">Eski (Bayat) Tedarikçi Stok Kuralı</div>
                <div class="card-body row g-3">
                    <div class="col-12 col-md-5">
                        <label class="form-label" for="store_stale_stock_hours">Eskime Eşiği (saat)</label>
                        <input class="form-control" type="number" min="1" id="store_stale_stock_hours" name="store_stale_stock_hours" value="<?= $v('store.stale_stock_hours') ?>">
                        <div class="form-text">Tedarikçi verisinin son görülme zamanı bu saatten eskiyse ürün "bayat" kabul edilir.</div>
                    </div>
                    <div class="col-12 col-md-7">
                        <label class="form-label" for="store_stale_stock_action">Bayat Ürün Davranışı</label>
                        <select class="form-select" id="store_stale_stock_action" name="store_stale_stock_action">
                            <option value="disable" <?= ($values['store.stale_stock_action'] ?? '') === 'disable' ? 'selected' : '' ?>>disable — Satın almaya kapat (listelerde görünür)</option>
                            <option value="hide" <?= ($values['store.stale_stock_action'] ?? '') === 'hide' ? 'selected' : '' ?>>hide — Tamamen gizle (listelerde görünmez)</option>
                        </select>
                        <div class="form-text">Hem sepete ekleme hem checkout bu kurala uyar.</div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-white fw-bold">Hukuki Metinler (TASLAK)</div>
                <div class="card-body">
                    <div class="alert alert-warning py-2 small">Bu metinler şablonudur; yayına almadan önce işletme bilgilerinize göre düzenleyin ve hukuk danışmanınıza kontrol ettirin. Boş bırakılırsa sistemdeki varsayılan TASLAK kullanılır. Yer tutucular: {{STORE_NAME}}, {{COMPANY_TITLE}}, {{EMAIL}}, {{PHONE}}, {{ADDRESS}}.</div>
                    <?php
                    $legalFields = [
                        'legal_privacy'          => ['Gizlilik & KVKK', 'legal.privacy'],
                        'legal_distance_sales'   => ['Mesafeli Satış Sözleşmesi', 'legal.distance_sales'],
                        'legal_preinformation'   => ['Ön Bilgilendirme Formu', 'legal.preinformation'],
                        'legal_returns_delivery' => ['İade ve Teslimat', 'legal.returns_delivery'],
                    ];
                    foreach ($legalFields as $field => [$label, $key]): ?>
                        <label class="form-label mt-2" for="<?= e($field) ?>"><?= e($label) ?></label>
                        <textarea class="form-control font-monospace" id="<?= e($field) ?>" name="<?= e($field) ?>" rows="6"><?= e($values[$key] ?? '') ?></textarea>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Ayarları Kaydet</button>
        </form>
    </div>

    <div class="col-12 col-xl-4">
        <div class="card">
            <div class="card-header bg-white fw-bold">Yardım</div>
            <div class="card-body small text-muted">
                <p><strong>Mağaza bilgisi</strong> — footer, iletişim sayfası ve hukuki metinlerin yer tutucularında kullanılır.</p>
                <p><strong>Kargo</strong> — sepet ve checkout özetinde sunucu tarafında hesaplanır.</p>
                <p><strong>Bayat stok kuralı</strong> — XML senkronizasyonunda ürünün tedarikçi verisi <code>product_supplier_data.last_seen_at</code> üzerinden ölçülür. Test için bir ürünün <code>last_seen_at</code> değerini geçmişe almanız yeterlidir.</p>
                <p><strong>Hukuki metinler</strong> — vitrin sayfalarında "TASLAK" rozetiyle görünür; canlıya almadan önce mutlaka güncelleyin.</p>
            </div>
        </div>
    </div>
</div>
