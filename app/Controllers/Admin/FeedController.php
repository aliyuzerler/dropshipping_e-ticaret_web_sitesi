<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\FeedRepository;
use App\Repositories\SupplierRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Http\UrlFetcher;
use App\Services\Security\Cipher;
use App\Validators\Validator;
use App\Validators\XmlUploadValidator;

/**
 * XML kaynakları (feed) yönetimi.
 *
 *  GET  /admin/feeds              → liste
 *  GET  /admin/feeds/new          → ekleme formu
 *  POST /admin/feeds              → kaydet
 *  GET  /admin/feeds/{id}/edit    → düzenleme formu
 *  POST /admin/feeds/{id}         → güncelle
 *  POST /admin/feeds/{id}/delete  → sil
 *  POST /admin/feeds/{id}/toggle  → aktif/pasif
 *  POST /admin/feeds/{id}/file    → upload feed'ine XML dosyası yükle
 *
 * Tam yol: app/Controllers/Admin/FeedController.php
 */
final class FeedController
{
    public function index(Request $request): Response
    {
        $feeds = (new FeedRepository())->listWithSupplier();
        $lastLogs = $this->lastLogsByFeed();
        $productCounts = $this->productCountsByFeed();

        foreach ($feeds as &$feed) {
            $feedId = (int) $feed['id'];
            $feed['last_log'] = $lastLogs[$feedId] ?? null;
            $feed['product_count'] = $productCounts[$feedId] ?? 0;
        }
        unset($feed);

        return View::render('admin/feeds/index', [
            'title'   => 'XML Kaynakları',
            'feeds'   => $feeds,
            'message' => get_flash('feed_message'),
            'error'   => get_flash('feed_error'),
        ]);
    }

    public function create(Request $request): Response
    {
        return View::render('admin/feeds/form', [
            'title'      => 'Yeni XML Kaynağı',
            'feed'       => null,
            'suppliers'  => (new SupplierRepository())->activeSuppliers(),
            'categories' => (new CategoryRepository())->allOrdered(),
            'error'      => get_flash('feed_error'),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validatedInput($request);
        if ($data === null) {
            return redirect('/admin/feeds/new');
        }

        $repo = new FeedRepository();
        $insert = $this->toColumns($data);
        $insert['created_at'] = date('Y-m-d H:i:s');
        $insert['updated_at'] = date('Y-m-d H:i:s');

        try {
            $id = $repo->insert($insert);
        } catch (\Throwable $e) {
            flash('feed_error', 'Kaynak kaydedilemedi: ' . $e->getMessage());
            return redirect('/admin/feeds/new');
        }

        $this->audit($request, 'feed.created', 'xml_feed', $id, ['name' => $data['name']]);
        flash('feed_message', 'XML kaynağı oluşturuldu. Şimdi alan eşleştirmesini yapın.');
        return redirect('/admin/feeds/' . $id . '/mapping');
    }

    public function edit(Request $request, string $id): Response
    {
        $feed = (new FeedRepository())->find((int) $id);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }
        return View::render('admin/feeds/form', [
            'title'      => 'Kaynağı Düzenle: ' . $feed['name'],
            'feed'       => $feed,
            'suppliers'  => (new SupplierRepository())->activeSuppliers(),
            'categories' => (new CategoryRepository())->allOrdered(),
            'error'      => get_flash('feed_error'),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $repo = new FeedRepository();
        $feed = $repo->find($feedId);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }

        $data = $this->validatedInput($request, $feed);
        if ($data === null) {
            return redirect('/admin/feeds/' . $feedId . '/edit');
        }

        $columns = $this->toColumns($data);

        // Şifre boş bırakıldıysa mevcut şifre korunur (toColumns auth_password'ü yalnız
        // yeni şifre girildiyse ekler). Aksi hâlde güncelleme yapılmaz.
        $columns['updated_at'] = date('Y-m-d H:i:s');

        try {
            $repo->update($feedId, $columns);
        } catch (\Throwable $e) {
            flash('feed_error', 'Güncelleme başarısız: ' . $e->getMessage());
            return redirect('/admin/feeds/' . $feedId . '/edit');
        }

        $this->audit($request, 'feed.updated', 'xml_feed', $feedId, ['name' => $data['name']]);
        flash('feed_message', 'XML kaynağı güncellendi.');
        return redirect('/admin/feeds');
    }

    public function destroy(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $repo = new FeedRepository();
        $feed = $repo->find($feedId);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }

        try {
            $repo->delete($feedId);
            $this->audit($request, 'feed.deleted', 'xml_feed', $feedId, ['name' => $feed['name']]);
            flash('feed_message', 'Kaynak silindi. Bağlı aktarım geçmişi arşiv olarak korunur.');
        } catch (\Throwable $e) {
            flash('feed_error', 'Silme başarısız: ' . $e->getMessage());
        }
        return redirect('/admin/feeds');
    }

    public function toggle(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $repo = new FeedRepository();
        $feed = $repo->find($feedId);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }
        $active = ((int) $feed['is_active']) === 1;
        $repo->setActive($feedId, !$active);
        $this->audit($request, 'feed.toggled', 'xml_feed', $feedId, ['is_active' => $active ? 0 : 1]);
        flash('feed_message', $active ? 'Kaynak pasife alındı.' : 'Kaynak aktifleştirildi.');
        return redirect('/admin/feeds');
    }

    /**
     * Upload feed'ine XML dosyası yükleme (eşleme/önizleme/senkron için örnek dosya).
     */
    public function uploadFile(Request $request, string $id): Response
    {
        $feedId = (int) $id;
        $repo = new FeedRepository();
        $feed = $repo->find($feedId);
        if ($feed === null) {
            flash('feed_error', 'Kaynak bulunamadı.');
            return redirect('/admin/feeds');
        }

        $file = $request->file('xml_file');
        if ($file === null) {
            flash('feed_error', 'Dosya seçilmedi.');
            return redirect('/admin/feeds/' . $feedId . '/mapping');
        }

        try {
            $validated = (new XmlUploadValidator())->validate($file);

            // Upload feed dosyaları feed'e özel dizinde saklanır
            $stored = $this->storeForFeed($feedId, $validated);

            // İlk ürün düğümlerini hızlıca doğrula (dosya gerçekten okunabilir XML mi)
            $reader = new \App\Services\Import\XmlStreamReader();
            $count = $reader->parse($stored['path'], (string) $feed['product_node'], static function (array $f): void {
            });

            $repo->setLastFilePath($feedId, $stored['relative']);
            $this->audit($request, 'feed.file_uploaded', 'xml_feed', $feedId, [
                'file' => $validated['original_name'], 'rows' => $count['count'],
            ]);

            flash('feed_message', sprintf(
                'Dosya kaydedildi: %s — %d ürün düğümü okundu. Şimdi alan eşlemesini yapabilirsiniz.',
                $validated['original_name'],
                $count['count']
            ));
        } catch (\Throwable $e) {
            \App\Core\Logger::security('xml.feed_file_rejected', ['feed_id' => $feedId, 'reason' => $e->getMessage()]);
            flash('feed_error', 'Yükleme reddedildi: ' . $e->getMessage());
        }

        return redirect('/admin/feeds/' . $feedId . '/mapping');
    }

    /* --------------------------------------------------------------------- */

    /**
     * Form girdisini doğrular; hata varsa flash + null döner.
     * @return array<string,mixed>|null
     */
    private function validatedInput(Request $request, ?array $existingFeed = null): ?array
    {
        $sourceType = $request->string('source_type', 'upload');
        $validator = Validator::make($request->post(), [
            'name'              => 'required|max:150',
            'supplier_id'       => 'required|int',
            'source_type'       => 'in:upload,url',
            'source_url'        => $sourceType === 'url' ? 'required|url|max:1000' : 'max:1000',
            'auth_type'         => 'in:none,basic',
            'product_node'      => 'required|max:100',
            'encoding'          => 'max:30',
            'sku_prefix'        => 'max:20',
            'default_category_id' => 'int',
            'interval_minutes'  => 'required|int',
            'max_file_size_mb'  => 'required|int',
        ]);

        try {
            $validator->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash('feed_error', $e->firstError());
            return null;
        }

        $interval = (int) $request->string('interval_minutes', '60');
        if ($interval < 1) {
            flash('feed_error', 'Güncelleme sıklığı en az 1 dakika olmalıdır.');
            return null;
        }
        $maxMb = (int) $request->string('max_file_size_mb', '100');
        if ($maxMb < 1 || $maxMb > 1024) {
            flash('feed_error', 'Dosya boyut limiti 1–1024 MB arasında olmalıdır.');
            return null;
        }

        $sourceUrl = trim($request->string('source_url'));
        if ($sourceType === 'url') {
            // SSRF savunması: kayıt anında da doğrula (fetch anında tekrar kontrol edilir)
            try {
                (new UrlFetcher())->assertSafeUrl($sourceUrl);
            } catch (\Throwable $e) {
                flash('feed_error', 'URL reddedildi: ' . $e->getMessage());
                return null;
            }
        }

        $supplierId = (int) $request->string('supplier_id');
        if ((new SupplierRepository())->find($supplierId) === null) {
            flash('feed_error', 'Tedarikçi bulunamadı.');
            return null;
        }

        $passwordInput = (string) $request->input('auth_password', '');
        $authType = $request->string('auth_type', 'none');
        $isEdit = $existingFeed !== null;

        // basic seçiliyse şifre gerekli (düzenlemede boş = mevcut korunur)
        if ($authType === 'basic' && !$isEdit && trim($passwordInput) === '') {
            flash('feed_error', 'Basic kimlik doğrulaması için şifre girin.');
            return null;
        }

        return [
            'name'                => trim($request->string('name')),
            'supplier_id'         => $supplierId,
            'source_type'         => $sourceType === 'url' ? 'url' : 'upload',
            'source_url'          => $sourceType === 'url' ? $sourceUrl : null,
            'auth_type'           => $authType === 'basic' ? 'basic' : 'none',
            'auth_username'       => $authType === 'basic' ? trim($request->string('auth_username')) : null,
            'auth_password'       => $authType === 'basic' && trim($passwordInput) !== '' ? Cipher::encrypt($passwordInput) : null,
            'encoding'            => strtoupper(trim($request->string('encoding', 'UTF-8')) ?: 'UTF-8'),
            'product_node'        => trim($request->string('product_node', 'product')) ?: 'product',
            'sku_prefix'          => trim($request->string('sku_prefix')) !== '' ? trim($request->string('sku_prefix')) : null,
            'default_category_id' => trim($request->string('default_category_id')) !== '' ? (int) $request->string('default_category_id') : null,
            'interval_minutes'    => $interval,
            'max_file_size_mb'    => $maxMb,
            'update_description'  => $request->input('update_description') !== null ? 1 : 0,
            'update_images'       => $request->input('update_images') !== null ? 1 : 0,
            'update_price'        => $request->input('update_price') !== null ? 1 : 0,
            'update_stock'        => $request->input('update_stock') !== null ? 1 : 0,
            'auto_publish'        => $request->input('auto_publish') !== null ? 1 : 0,
            'deactivate_missing'  => $request->input('deactivate_missing') !== null ? 1 : 0,
            'is_active'           => $request->input('is_active') !== null ? 1 : 0,
        ];
    }

    /**
     * validatedInput çıktısını veritabanı kolonlarına çevirir.
     * Düzenlemede şifre boşsa mevcut şifre korunur.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function toColumns(array $data): array
    {
        $columns = [
            'name'                => $data['name'],
            'supplier_id'         => $data['supplier_id'],
            'source_type'         => $data['source_type'],
            'source_url'          => $data['source_url'],
            'auth_type'           => $data['auth_type'],
            'auth_username'       => $data['auth_username'],
            'encoding'            => $data['encoding'],
            'product_node'        => $data['product_node'],
            'sku_prefix'          => $data['sku_prefix'],
            'default_category_id' => $data['default_category_id'],
            'interval_minutes'    => $data['interval_minutes'],
            'max_file_size_mb'    => $data['max_file_size_mb'],
            'update_description'  => $data['update_description'],
            'update_images'       => $data['update_images'],
            'update_price'        => $data['update_price'],
            'update_stock'        => $data['update_stock'],
            'auto_publish'        => $data['auto_publish'],
            'deactivate_missing'  => $data['deactivate_missing'],
            'is_active'           => $data['is_active'],
        ];
        if ($data['auth_password'] !== null) {
            $columns['auth_password'] = $data['auth_password'];
        }
        return $columns;
    }

    /**
     * Dosyayı feed'e özel dizine kaydeder (XmlUploadManager'ın feed uyarlaması).
     * @param array{tmp_name:string, original_name:string, size:int, stored_name:string} $validated
     * @return array{path:string, relative:string}
     */
    private function storeForFeed(int $feedId, array $validated): array
    {
        $dir = storage_path('imports/feeds/' . $feedId);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Feed dizini oluşturulamadı.');
        }
        $target = $dir . '/' . $validated['stored_name'];
        if (!@move_uploaded_file($validated['tmp_name'], $target)
            && !@rename($validated['tmp_name'], $target)
            && !@copy($validated['tmp_name'], $target)) {
            throw new \RuntimeException('Dosya kaydedilemedi.');
        }
        @chmod($target, 0640);

        return [
            'path'     => $target,
            'relative' => 'imports/feeds/' . $feedId . '/' . $validated['stored_name'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function lastLogsByFeed(): array
    {
        try {
            $pdo = Database::pdo();
            $rows = $pdo->query(
                'SELECT l.* FROM xml_import_logs l
                 INNER JOIN (SELECT xml_feed_id, MAX(id) AS max_id FROM xml_import_logs
                             WHERE xml_feed_id IS NOT NULL GROUP BY xml_feed_id) t
                 ON t.max_id = l.id'
            )->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                $out[(int) $row['xml_feed_id']] = $row;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,int> */
    private function productCountsByFeed(): array
    {
        try {
            $pdo = Database::pdo();
            $rows = $pdo->query(
                'SELECT xml_feed_id, COUNT(*) AS n FROM product_supplier_data
                 WHERE xml_feed_id IS NOT NULL GROUP BY xml_feed_id'
            )->fetchAll();
            $out = [];
            foreach ($rows as $row) {
                $out[(int) $row['xml_feed_id']] = (int) $row['n'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function audit(Request $request, string $action, string $entityType, ?int $entityId, array $context): void
    {
        AuditLogger::log(
            action: $action,
            adminUserId: Session::get('admin_id') !== null ? (int) Session::get('admin_id') : null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            entityType: $entityType,
            entityId: $entityId,
            context: $context
        );
    }
}
