<?php
/**
 * Hesabım paneli: özet kartları + son siparişler + profil ve şifre formları.
 * Tam yol: app/Views/store/account/dashboard.php
 * Beklenen: $customer, $orderCount, $addressCount, $recentOrders, $errors?
 * (alan bazlı; profil hataları first_name/last_name/phone, şifre hataları
 * current_password/password/password_confirmation anahtarları altındadır)
 */
$customer     = is_array($customer ?? null) ? $customer : [];
$orderCount   = (int) ($orderCount ?? 0);
$addressCount = (int) ($addressCount ?? 0);
$recentOrders = is_array($recentOrders ?? null) ? $recentOrders : [];
$errors       = is_array($errors ?? null) ? $errors : [];

/** AŞAMA 4: durum etiketleri OrderStatus durum makinesinden gelir. */
$statusOf = static function (mixed $s): array {
    return [order_status_label($s), order_status_badge($s)];
};
?>
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="/">Ana Sayfa</a></li>
            <li class="breadcrumb-item active" aria-current="page">Hesabım</li>
        </ol>
    </nav>

    <h1 class="section-title mb-4">Hesabım</h1>

    <div class="row g-4">
        <!-- Sol sütun: karşılama + özet + hızlı bağlantılar -->
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <p class="text-muted small mb-1">Merhaba,</p>
                    <h2 class="h5 fw-bold mb-1"><?= e(trim((string) ($customer['first_name'] ?? '') . ' ' . (string) ($customer['last_name'] ?? ''))) ?></h2>
                    <p class="small text-muted mb-2"><?= e((string) ($customer['email'] ?? '')) ?></p>
                    <?php if (!empty($customer['created_at'])): ?>
                        <p class="small text-muted mb-0">Üyelik: <?= e(date('d.m.Y', strtotime((string) $customer['created_at']))) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-6">
                    <a class="card shadow-sm border-0 text-decoration-none h-100" href="/hesabim/siparisler">
                        <div class="card-body text-center">
                            <div class="fs-3" aria-hidden="true">🧾</div>
                            <div class="fs-4 fw-bold"><?= $orderCount ?></div>
                            <div class="small text-muted">Siparişim</div>
                        </div>
                    </a>
                </div>
                <div class="col-6">
                    <a class="card shadow-sm border-0 text-decoration-none h-100" href="/hesabim/adresler">
                        <div class="card-body text-center">
                            <div class="fs-3" aria-hidden="true">📍</div>
                            <div class="fs-4 fw-bold"><?= $addressCount ?></div>
                            <div class="small text-muted">Adresim</div>
                        </div>
                    </a>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">Hızlı Bağlantılar</div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item"><a class="text-decoration-none" href="/hesabim/siparisler">🧾 Siparişlerim</a></li>
                    <li class="list-group-item"><a class="text-decoration-none" href="/hesabim/adresler">📍 Adreslerim</a></li>
                    <li class="list-group-item"><a class="text-decoration-none" href="/sepet">🧺 Sepetim</a></li>
                    <li class="list-group-item">
                        <form method="post" action="/cikis" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-link p-0 text-danger text-decoration-none">🚪 Çıkış Yap</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Sağ sütun: profil + şifre formları -->
        <div class="col-12 col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-bold">👤 Profil Bilgilerim</div>
                <div class="card-body">
                    <form method="post" action="/hesabim/profil" novalidate>
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="first_name">Ad <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control" id="first_name" name="first_name"
                                       value="<?= e(old('first_name', (string) ($customer['first_name'] ?? ''))) ?>"
                                       maxlength="80" required aria-describedby="firstNameError">
                                <div id="firstNameError">
                                    <?php foreach (($errors['first_name'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="last_name">Soyad <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="text" class="form-control" id="last_name" name="last_name"
                                       value="<?= e(old('last_name', (string) ($customer['last_name'] ?? ''))) ?>"
                                       maxlength="80" required aria-describedby="lastNameError">
                                <div id="lastNameError">
                                    <?php foreach (($errors['last_name'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="phone">Telefon</label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                       value="<?= e(old('phone', (string) ($customer['phone'] ?? ''))) ?>"
                                       maxlength="40" autocomplete="tel" aria-describedby="phoneError">
                                <div id="phoneError">
                                    <?php foreach (($errors['phone'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="email_static">E-posta</label>
                                <input type="email" class="form-control-plaintext" id="email_static"
                                       value="<?= e((string) ($customer['email'] ?? '')) ?>" readonly>
                                <div class="form-text">E-posta adresi değiştirilemez.</div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-brand mt-3">Profili Güncelle</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold">🔒 Şifre Değiştir</div>
                <div class="card-body">
                    <form method="post" action="/hesabim/sifre" novalidate>
                        <?= csrf_field() ?>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="current_password">Mevcut Şifre <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="password" class="form-control" id="current_password" name="current_password"
                                       autocomplete="current-password" required aria-describedby="currentPasswordError">
                                <div id="currentPasswordError">
                                    <?php foreach (($errors['current_password'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="password">Yeni Şifre <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="password" class="form-control" id="password" name="password"
                                       minlength="8" autocomplete="new-password" required aria-describedby="passwordError">
                                <div id="passwordError">
                                    <?php foreach (($errors['password'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="password_confirmation">Yeni Şifre (Tekrar) <span class="text-danger" aria-hidden="true">*</span></label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                                       minlength="8" autocomplete="new-password" required aria-describedby="passwordConfirmationError">
                                <div id="passwordConfirmationError">
                                    <?php foreach (($errors['password_confirmation'] ?? []) as $m): ?><div class="text-danger small"><?= e($m) ?></div><?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <p class="form-text small mb-0 mt-2">En az 8 karakter; harf, rakam ve simge karışımı önerilir.</p>
                        <button type="submit" class="btn btn-outline-brand mt-3">Şifreyi Değiştir</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Son siparişler -->
    <div class="card shadow-sm border-0 mt-4">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span>🧾 Son Siparişlerim</span>
            <a class="small text-decoration-none" href="/hesabim/siparisler">Tümünü Gör</a>
        </div>
        <?php if ($recentOrders === []): ?>
            <div class="card-body">
                <p class="text-muted mb-2">Henüz siparişiniz yok.</p>
                <a class="btn btn-outline-brand btn-sm" href="/">Alışverişe Başla</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <caption class="visually-hidden">Son 5 sipariş</caption>
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Sipariş No</th>
                            <th scope="col">Tarih</th>
                            <th scope="col" class="text-center">Ürün</th>
                            <th scope="col" class="text-end">Tutar</th>
                            <th scope="col">Durum</th>
                            <th scope="col"><span class="visually-hidden">İşlemler</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $o): ?>
                            <?php $status = $statusOf($o['status'] ?? ''); ?>
                            <tr>
                                <td class="fw-semibold"><?= e((string) ($o['order_number'] ?? '')) ?></td>
                                <td><?= e(!empty($o['placed_at']) ? date('d.m.Y H:i', strtotime((string) $o['placed_at'])) : '-') ?></td>
                                <td class="text-center"><?= (int) ($o['item_count'] ?? 0) ?></td>
                                <td class="text-end"><?= money($o['grand_total'] ?? 0) ?></td>
                                <td><span class="badge text-bg-<?= e($status[1]) ?>"><?= e($status[0]) ?></span></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-brand" href="/hesabim/siparisler/<?= e((string) ($o['order_number'] ?? '')) ?>">Detay</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
