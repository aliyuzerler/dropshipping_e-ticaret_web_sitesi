<?php
/**
 * XML yükleme sayfası.
 * Tam yol: app/Views/admin/xml.php
 *
 * Değişkenler: $title, $message, $error, $uploads
 */
?>
<h1 class="h3 mb-4">XML Yükleri</h1>

<?php if (!empty($message)): ?>
    <div class="alert alert-success" role="alert"><?= e($message) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-12 col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5 card-title">Güvenli XML Yükleme</h2>
                <p class="text-body-secondary small">
                    Doğrulama katmanları: boyut limiti, uzantı, MIME (finfo), içerik sniff,
                    DOCTYPE/ENTITY reddi (XXE), rastgele dosya adı, <code>storage/imports</code> altına 0640 izniyle kayıt.
                </p>
                <form method="post" action="/admin/xml/upload" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="xml_file" class="form-label">XML dosyası (max <?= e(config('xml.upload.max_mb', 100)) ?> MB)</label>
                        <input type="file" class="form-control" id="xml_file" name="xml_file" accept=".xml,text/xml,application/xml" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Yükle ve Doğrula</button>
                </form>
                <div class="alert alert-info small mt-3 mb-0" role="note">
                    Bu uç <strong>yükleme altyapısını</strong> çalıştırır: dosyayı doğrular, güvenle saklar,
                    XMLReader ile akış halinde okur ve <code>&lt;product&gt;</code> düğüm sayısını raporlar.
                    Ürün/eşleme yazımı (xml_field_mappings) Faz 2'de devreye girer.
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white"><h2 class="h5 mb-0">Son Yüklemeler</h2></div>
            <div class="card-body p-0">
                <?php if ($uploads === []): ?>
                    <p class="text-body-secondary p-3 mb-0">Henüz yükleme yok.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height: 24rem; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Dosya</th>
                                    <th scope="col">Durum</th>
                                    <th scope="col">Satır</th>
                                    <th scope="col">Tarih</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uploads as $up): ?>
                                    <tr>
                                        <td><?= e($up['id'] ?? '') ?></td>
                                        <td class="text-truncate" style="max-width:240px" title="<?= e($up['file_name'] ?? '') ?>"><?= e($up['file_name'] ?? '') ?></td>
                                        <td>
                                            <?php $s = (string) ($up['status'] ?? ''); ?>
                                            <span class="badge <?= $s === 'success' ? 'text-bg-success' : ($s === 'failed' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= e($s) ?></span>
                                        </td>
                                        <td><?= e($up['total_rows'] ?? 0) ?></td>
                                        <td class="small text-body-secondary"><?= e($up['created_at'] ?? '') ?></td>
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
