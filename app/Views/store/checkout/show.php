<?php
/**
 * Checkout ekranı — adres seçimi + özet. GERÇEK ÖDEME YOK (Aşama 4).
 * Tam yol: app/Views/store/checkout/show.php
 * Beklenen: $summary, $addresses, $defaultAddress, $customer
 */
$addresses = $addresses ?? [];
$defaultAddress = $defaultAddress ?? null;
$hasAddresses = $addresses !== [];
$selectedMode = ($hasAddresses && ($defaultAddress !== null || old('address_mode', 'existing') === 'existing')) ? 'existing' : 'new';
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item"><a href="/sepet">Sepetim</a></li>
            <li class="breadcrumb-item active" aria-current="page">Ödeme</li>
        </ol>
    </nav>

    <h1 class="section-title mb-4">Siparişi Tamamla</h1>

    <div class="alert alert-info" role="alert">
        ℹ️ <strong>Bilgilendirme:</strong> Gerçek ödeme başlatma bu aşamada devre dışıdır. Siparişiniz "Onay Bekliyor" durumunda oluşturulur; ödeme adımı bir sonraki aşamada (iyzico) aktifleşecektir.
    </div>

    <form method="post" action="/odeme/tamamla" novalidate>
        <?= csrf_field() ?>
        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <!-- Teslimat adresi -->
                <div class="card mb-4">
                    <div class="card-header bg-white fw-bold">1) Teslimat Adresi</div>
                    <div class="card-body">
                        <?php if ($hasAddresses): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="radio" name="address_mode" id="modeExisting" value="existing"
                                       <?= $selectedMode === 'existing' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="modeExisting">Kayıtlı adreslerimden seç</label>
                            </div>
                            <div id="existingAddressBox" class="ps-3 <?= $selectedMode === 'existing' ? '' : 'd-none' ?>">
                                <div class="row g-2 scroll-y">
                                    <?php foreach ($addresses as $i => $addr): ?>
                                        <div class="col-12">
                                            <div class="form-check border rounded p-2 <?= ($defaultAddress !== null ? (int) $defaultAddress['id'] : (int) $addresses[0]['id']) === (int) $addr['id'] ? 'border-brand-subtle bg-light' : '' ?>">
                                                <input class="form-check-input" type="radio" name="address_id" id="addr<?= (int) $addr['id'] ?>"
                                                       value="<?= (int) $addr['id'] ?>"
                                                       <?= ($defaultAddress !== null ? (int) $defaultAddress['id'] : (int) $addresses[0]['id']) === (int) $addr['id'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="addr<?= (int) $addr['id'] ?>">
                                                    <strong><?= e($addr['title'] ?: 'Adres') ?></strong>
                                                    <?= (int) $addr['is_default'] === 1 ? '<span class="badge text-bg-success ms-1">Varsayılan</span>' : '' ?><br>
                                                    <span class="small text-muted">
                                                        <?= e($addr['full_name']) ?> · <?= e((string) $addr['city']) ?>/<?= e((string) $addr['district']) ?> · <?= e($addr['line1']) ?>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <hr>
                        <?php endif; ?>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="address_mode" id="modeNew" value="new"
                                   <?= $selectedMode === 'new' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="modeNew">Yeni adres kullan</label>
                        </div>

                        <div id="newAddressBox" class="ps-3 <?= $selectedMode === 'new' ? '' : 'd-none' ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="fullName">Ad Soyad <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="fullName" name="full_name"
                                           value="<?= e(old('full_name', (string) ($_SESSION['customer_name'] ?? ''))) ?>" maxlength="160" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="city">İl <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="city" name="city" value="<?= e(old('city')) ?>" maxlength="80" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="district">İlçe <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="district" name="district" value="<?= e(old('district')) ?>" maxlength="80" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="postalCode">Posta Kodu</label>
                                    <input type="text" class="form-control" id="postalCode" name="postal_code" value="<?= e(old('postal_code')) ?>" maxlength="16">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label" for="phone">Telefon</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?= e(old('phone')) ?>" maxlength="40">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="line1">Açık Adres <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="line1" name="line1" rows="2" maxlength="255" required><?= e(old('line1')) ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="saveAddress" name="save_address" value="1">
                                        <label class="form-check-label" for="saveAddress">Bu adresi adres defterime kaydet</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sipariş notu -->
                <div class="card mb-4">
                    <div class="card-header bg-white fw-bold">2) Sipariş Notu (opsiyonel)</div>
                    <div class="card-body">
                        <label class="form-label" for="orderNote">Kurye için not / özel istekler</label>
                        <textarea class="form-control" id="orderNote" name="note" rows="2" maxlength="1000"><?= e(old('note')) ?></textarea>
                    </div>
                </div>

                <!-- Onay -->
                <div class="card">
                    <div class="card-header bg-white fw-bold">3) Onay</div>
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="terms" name="terms" value="1" required>
                            <label class="form-check-label" for="terms">
                                <a href="/mesafeli-satis-sozlesmesi" target="_blank" rel="noopener">Mesafeli satış sözleşmesini</a> ve
                                <a href="/on-bilgilendirme-formu" target="_blank" rel="noopener">ön bilgilendirme formunu</a> okudum, onaylıyorum. <span class="text-danger">*</span>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-brand w-100 mt-3" data-add-to-cart="1">
                            Siparişi Onayla — <?= money($summary['grand_total']) ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sipariş özeti -->
            <div class="col-12 col-lg-5">
                <div class="summary-card p-3 bg-white">
                    <h2 class="h5 mb-3">Sipariş Özeti</h2>
                    <div class="scroll-y mb-2">
                        <?php foreach ($summary['items'] as $item): ?>
                            <div class="d-flex gap-2 align-items-center py-1 border-bottom">
                                <?php if (!empty($item['image_path'])): ?>
                                    <img src="<?= e($item['image_path']) ?>" alt="" width="44" height="44" class="rounded" loading="lazy" style="object-fit:cover">
                                <?php else: ?>
                                    <div class="rounded bg-secondary-subtle d-flex align-items-center justify-content-center" style="width:44px;height:44px">📦</div>
                                <?php endif; ?>
                                <div class="flex-grow-1 small">
                                    <div class="fw-semibold text-truncate"><?= e($item['name']) ?></div>
                                    <div class="text-muted"><?= (int) $item['quantity'] ?> adet × <?= money($item['unit_price']) ?></div>
                                </div>
                                <div class="small fw-bold"><?= money($item['line_total']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="summary-row"><span>Ara Toplam</span><strong><?= money($summary['subtotal']) ?></strong></div>
                    <?php if ($summary['discount_total'] > 0 && $summary['coupon'] !== null): ?>
                        <div class="summary-row text-success"><span>Kupon (<?= e($summary['coupon']['code']) ?>)</span><strong>−<?= money($summary['discount_total']) ?></strong></div>
                    <?php endif; ?>
                    <div class="summary-row"><span>Kargo</span><strong><?= $summary['shipping_total'] > 0 ? money($summary['shipping_total']) : 'Ücretsiz' ?></strong></div>
                    <hr>
                    <div class="summary-row summary-total"><span>Toplam</span><span><?= money($summary['grand_total']) ?></span></div>
                    <p class="small text-muted mb-0">Fiyatlar KDV dahildir.</p>
                </div>
            </div>
        </div>
    </form>
</div>
