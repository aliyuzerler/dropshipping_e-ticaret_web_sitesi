<?php
/**
 * XML alan eşleştirme ekranı.
 * Tam yol: app/Views/admin/feeds/mapping.php
 *
 * Değişkenler: $title, $feed, $mappings, $targets, $detected, $hasFile, $message, $error
 */
?>
<?php echo \App\Core\View::partial('admin/_nav', ['active' => 'feeds']); ?>
<?php
$feed = is_array($feed ?? null) ? $feed : [];
$feedId = (int) ($feed['id'] ?? 0);
$mappings = is_array($mappings ?? null) ? $mappings : [];
$targets = is_array($targets ?? null) ? $targets : [];
$detected = is_array($detected ?? null) ? $detected : [];
$hasFile = !empty($hasFile);

$extraRows = max(5 - count($mappings), 3);
$renderRows = [];
foreach ($mappings as $m) {
    $renderRows[] = [
        'source'    => (string) ($m['source_path'] ?? ''),
        'target'    => (string) ($m['target_field'] ?? ''),
        'transform' => (string) ($m['transform'] ?? ''),
        'default'   => (string) ($m['default_value'] ?? ''),
        'template'  => false,
    ];
}
for ($i = 0; $i < $extraRows; $i++) {
    $renderRows[] = ['source' => '', 'target' => '', 'transform' => '', 'default' => '', 'template' => false];
}
$renderRows[] = ['source' => '', 'target' => '', 'transform' => '', 'default' => '', 'template' => true];

$mappedTargets = [];
foreach ($mappings as $m) {
    $t = (string) ($m['target_field'] ?? '');
    if ($t !== '') {
        $mappedTargets[$t] = true;
    }
}

$transformOptions = [
    ''           => '— yok —',
    'upper'      => 'upper (BÜYÜK HARF)',
    'lower'      => 'lower (küçük harf)',
    'number'     => 'number (sayıya çevir)',
    'int'        => 'int (tam sayı)',
    'strip_tags' => 'strip_tags (HTML temizle)',
    'default'    => 'default (varsayılan değer)',
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= e($title ?? 'Alan Eşleştirme') ?></h1>
    <a href="/admin/feeds" class="btn btn-outline-secondary btn-sm">Kaynaklara Dön</a>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<?php if (!$hasFile): ?>
    <div class="alert alert-warning" role="alert">
        Önce örnek XML dosyası yükleyin. Alan tespiti ve eşleme, yüklenen dosyadaki gerçek alan adlarına göre yapılır.
    </div>
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h2 class="h5 card-title">Örnek XML Dosyası Yükle</h2>
            <p class="small text-body-secondary">
                Beklenen ürün düğümü: <code><?= e($feed['product_node'] ?? 'product') ?></code>.
                Dosya güvenli yükleyiciden geçer (boyut/uzantı/MIME/içerik denetimi) ve feed'e özel dizine kaydedilir.
            </p>
            <form method="post" action="<?= e('/admin/feeds/' . $feedId . '/file') ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-12 col-md-auto">
                    <label for="xml_file" class="form-label">XML dosyası</label>
                    <input type="file" class="form-control" id="xml_file" name="xml_file" accept=".xml" required>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-primary">Yükle</button>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="row g-4">
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body p-3">
                    <h2 class="h5 card-title">Tespit Edilen XML Alanları</h2>
                    <p class="small text-body-secondary">
                        Son yüklenen dosyanın ilk ürün düğümlerinden çıkarılan alan adları.
                        Ürün düğümü: <code><?= e($feed['product_node'] ?? 'product') ?></code>
                    </p>
                    <?php if ($detected === []): ?>
                        <p class="text-body-secondary small mb-0">Alan tespit edilemedi. Dosyayı yeniden yüklemeyi deneyin.</p>
                    <?php else: ?>
                        <div>
                            <?php foreach ($detected as $field): ?>
                                <span class="badge text-bg-light border me-1 mb-1 fw-normal"><?= e((string) $field) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h2 class="h5 mb-0">Eşleme Profili</h2>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="add-mapping-row">+ Satır Ekle</button>
                </div>
                <div class="card-body p-3">
                    <form method="post" action="<?= e('/admin/feeds/' . $feedId . '/mapping') ?>">
                        <?= csrf_field() ?>
                        <div class="table-responsive" style="max-height: 32rem; overflow-y: auto;">
                            <table class="table table-sm align-middle mb-2" id="mappingTable">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">XML Alanı (Kaynak)</th>
                                        <th scope="col">Hedef Alan</th>
                                        <th scope="col">Dönüşüm</th>
                                        <th scope="col">Varsayılan Değer</th>
                                    </tr>
                                </thead>
                                <tbody id="mappingRows">
                                    <?php $rowNo = 0; foreach ($renderRows as $r): $rowNo++; ?>
                                        <tr<?= $r['template'] ? ' class="d-none" data-mapping-template="1"' : '' ?>>
                                            <td class="text-body-secondary small"><?= $r['template'] ? '+' : $rowNo - 1 ?></td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="mapping_source[]"
                                                       list="detectedFields" value="<?= e($r['source']) ?>">
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm" name="mapping_target[]">
                                                    <option value="">— hedef seçin —</option>
                                                    <?php foreach ($targets as $tKey => $tLabel): ?>
                                                        <option value="<?= e((string) $tKey) ?>"<?= $r['target'] === (string) $tKey ? ' selected' : '' ?>><?= e((string) $tLabel) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm" name="mapping_transform[]">
                                                    <?php foreach ($transformOptions as $tOpt => $tLbl): ?>
                                                        <option value="<?= e((string) $tOpt) ?>"<?= $r['transform'] === (string) $tOpt ? ' selected' : '' ?>><?= e((string) $tLbl) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="mapping_default[]" value="<?= e($r['default']) ?>">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <datalist id="detectedFields">
                            <?php foreach ($detected as $field): ?>
                                <option value="<?= e((string) $field) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>

                        <table class="table table-sm table-light small text-body-secondary mb-3">
                            <tbody>
                                <tr>
                                    <th scope="row" class="fw-semibold">Zorunlu hedefler</th>
                                    <td>Tedarikçi Ürün Kodu ve Ürün Adı.</td>
                                </tr>
                                <tr>
                                    <th scope="row" class="fw-semibold">Alış fiyatı</th>
                                    <td>Sayısal olmalı (1.234,56 veya 1234.56).</td>
                                </tr>
                                <tr>
                                    <th scope="row" class="fw-semibold">Görsel / Varyant</th>
                                    <td>Çoklu seçilebilir; değerler <code>|</code> ile ayrılır.</td>
                                </tr>
                            </tbody>
                        </table>

                        <h3 class="h6">Hedef Alan Durumu</h3>
                        <p class="mb-3">
                            <?php foreach ($targets as $tKey => $tLabel): ?>
                                <span class="badge <?= isset($mappedTargets[(string) $tKey]) ? 'text-bg-success' : 'text-bg-secondary' ?> me-1 mb-1 fw-normal">
                                    <?= e((string) $tLabel) ?><?= isset($mappedTargets[(string) $tKey]) ? ' (eşlendi)' : '' ?>
                                </span>
                            <?php endforeach; ?>
                        </p>

                        <button type="submit" class="btn btn-primary">Eşleme Profilini Kaydet</button>
                        <a href="<?= e('/admin/feeds/' . $feedId . '/preview') ?>" class="btn btn-outline-info">Önizlemeye Git</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
