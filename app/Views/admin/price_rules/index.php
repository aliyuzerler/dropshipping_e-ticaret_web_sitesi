<?php
/**
 * Fiyat kuralları: ekleme formu + kurallar listesi.
 * Tam yol: app/Views/admin/price_rules/index.php
 *
 * Değişkenler: $title, $rules, $types, $scopes, $suppliers, $categories, $threshold, $message, $error
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'rules']); ?>
<?php
$rules = is_array($rules ?? null) ? $rules : [];
$types = is_array($types ?? null) ? $types : [];
$scopes = is_array($scopes ?? null) ? $scopes : [];
$suppliers = is_array($suppliers ?? null) ? $suppliers : [];
$categories = is_array($categories ?? null) ? $categories : [];
$threshold = (float) ($threshold ?? 10.0);

$fmtValue = static function (mixed $value, string $type): string {
    $num = e(number_format((float) $value, 2, ',', '.'));
    return match ($type) {
        'percent'            => $num . ' %',
        'fixed', 'min_price' => $num . ' TRY',
        default              => $num,
    };
};
$scopeName = static function (array $rule): string {
    return match ((string) ($rule['scope'] ?? '')) {
        'supplier' => (string) ($rule['supplier_name'] ?? ''),
        'category' => (string) ($rule['category_name'] ?? ''),
        'product'  => (string) ($rule['product_name'] ?? ''),
        default    => '',
    };
};
$validity = static function (array $rule): string {
    $parts = [];
    if (!empty($rule['starts_at'])) {
        $parts[] = 'Başlangıç: ' . e(substr((string) $rule['starts_at'], 0, 16));
    }
    if (!empty($rule['ends_at'])) {
        $parts[] = 'Bitiş: ' . e(substr((string) $rule['ends_at'], 0, 16));
    }
    return implode(' · ', $parts);
};
?>
<h1 class="h3 mb-4"><?= e($title ?? 'Fiyat Kuralları') ?></h1>

<div class="alert alert-info small" role="alert">
    Kurallar senkronizasyon sırasında alış fiyatına uygulanır (öncelik: priority ASC).
    Kâr marjı <?= e(number_format($threshold, 2, ',', '.')) ?>% altına düşen ürünler uyarı olarak işaretlenir.
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body p-4">
                <h2 class="h5 card-title mb-4">Yeni Kural Ekle</h2>
                <form method="post" action="/admin/price-rules">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="rule_name" class="form-label">Kural Adı <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="rule_name" name="name" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label for="rule_type" class="form-label">Tip <span class="text-danger">*</span></label>
                        <select class="form-select" id="rule_type" name="type" required>
                            <?php foreach ($types as $typeKey => $typeLabel): ?>
                                <option value="<?= e((string) $typeKey) ?>"><?= e((string) $typeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="rule_scope" class="form-label">Kapsam <span class="text-danger">*</span></label>
                        <select class="form-select" id="rule_scope" name="scope" required>
                            <?php foreach ($scopes as $scopeKey => $scopeLabel): ?>
                                <option value="<?= e((string) $scopeKey) ?>"><?= e((string) $scopeLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="rule_value" class="form-label">Değer <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="rule_value" name="value" step="0.01" required>
                        <div class="form-text" id="ruleValueHelp"></div>
                    </div>
                    <div class="mb-3">
                        <label for="rule_supplier" class="form-label">Tedarikçi</label>
                        <select class="form-select" id="rule_supplier" name="supplier_id">
                            <option value="">—</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= e($supplier['id'] ?? '') ?>"><?= e($supplier['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Yalnız tedarikçi kapsamında kullanılır.</div>
                    </div>
                    <div class="mb-3">
                        <label for="rule_category" class="form-label">Kategori</label>
                        <select class="form-select" id="rule_category" name="category_id">
                            <option value="">—</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= e($category['id'] ?? '') ?>"><?= e($category['name'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Yalnız kategori kapsamında kullanılır (üst kategori zinciri de kapsanır).</div>
                    </div>
                    <div class="mb-3">
                        <label for="rule_product" class="form-label">Ürün ID</label>
                        <input type="number" class="form-control" id="rule_product" name="product_id" min="1">
                        <div class="form-text">Yalnız ürün kapsamı; ürün ID'si girin.</div>
                    </div>
                    <div class="mb-3">
                        <label for="rule_priority" class="form-label">Öncelik</label>
                        <input type="number" class="form-control" id="rule_priority" name="priority" value="0" min="0">
                        <div class="form-text">Küçük değer önce uygulanır (priority ASC).</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="rule_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="rule_active">Kural aktif</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Kural Ekle</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Kurallar</h2></div>
            <div class="card-body p-0">
                <?php if ($rules === []): ?>
                    <p class="text-body-secondary p-4 mb-0">
                        Henüz fiyat kuralı yok. Sol taraftaki formla ilk kuralı ekleyin; kurallar senkronizasyon sırasında devreye girer.
                    </p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 32rem; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Ad</th>
                                    <th scope="col">Tip</th>
                                    <th scope="col">Kapsam</th>
                                    <th scope="col">Değer</th>
                                    <th scope="col">Öncelik</th>
                                    <th scope="col">Durum</th>
                                    <th scope="col">İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rules as $rule): ?>
                                    <?php
                                    $ruleId = (int) ($rule['id'] ?? 0);
                                    $ruleType = (string) ($rule['type'] ?? '');
                                    $ruleScope = (string) ($rule['scope'] ?? '');
                                    $ruleActive = (int) ($rule['is_active'] ?? 0) === 1;
                                    ?>
                                    <tr>
                                        <td><?= e($ruleId) ?></td>
                                        <td>
                                            <span class="fw-semibold"><?= e($rule['name'] ?? '-') ?></span>
                                            <?php $validityText = $validity($rule); ?>
                                            <?php if ($validityText !== ''): ?>
                                                <div class="small text-body-secondary"><?= $validityText ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= e((string) ($types[$ruleType] ?? $ruleType)) ?></td>
                                        <td>
                                            <?= e((string) ($scopes[$ruleScope] ?? $ruleScope)) ?>
                                            <?php $sName = $scopeName($rule); ?>
                                            <?php if ($sName !== ''): ?>
                                                <div class="small text-body-secondary"><?= e($sName) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $fmtValue($rule['value'] ?? 0, $ruleType) ?></td>
                                        <td><?= e($rule['priority'] ?? 0) ?></td>
                                        <td>
                                            <?php if ($ruleActive): ?>
                                                <span class="badge text-bg-success">Aktif</span>
                                            <?php else: ?>
                                                <span class="badge text-bg-secondary">Pasif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex flex-wrap gap-1">
                                                <form method="post" action="<?= e('/admin/price-rules/' . $ruleId . '/toggle') ?>" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-sm <?= $ruleActive ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                                        <?= $ruleActive ? 'Pasife Al' : 'Aktifleştir' ?>
                                                    </button>
                                                </form>
                                                <form method="post" action="<?= e('/admin/price-rules/' . $ruleId . '/delete') ?>" class="d-inline"
                                                      data-confirm="Bu fiyat kuralı silinsin mi?">
                                                    <?= csrf_field() ?>
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Sil</button>
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

<script>
(function () {
    var typeSel = document.getElementById('rule_type');
    var help = document.getElementById('ruleValueHelp');
    var labels = {
        percent: 'Yüzde değer (örn. 25 = %25 kâr artışı)',
        fixed: 'Sabit tutar (TRY)',
        min_price: 'Taban fiyat (TRY)',
        round: 'Yuvarlama adımı (örn. 1, 0.5)',
        round_end: 'Psikolojik fiyat sonu (örn. 0.99)'
    };
    function syncHelp() {
        if (typeSel && help) {
            help.textContent = labels[typeSel.value] || '';
        }
    }
    if (typeSel) {
        typeSel.addEventListener('change', syncHelp);
        syncHelp();
    }
})();
</script>
