<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\SupplierRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Security\Cipher;
use App\Validators\Validator;

/**
 * Tedarikçi yönetimi (dropshipping kaynakları).
 *
 *  GET  /admin/suppliers              → liste + ekleme formu
 *  POST /admin/suppliers              → ekle
 *  GET  /admin/suppliers/{id}/edit    → düzenleme formu
 *  POST /admin/suppliers/{id}         → güncelle
 *  POST /admin/suppliers/{id}/toggle  → aktif/pasif
 *  POST /admin/suppliers/{id}/delete  → sil (ürünü olan tedarikçi silinemez — FK RESTRICT)
 *
 * Tam yol: app/Controllers/Admin/SupplierController.php
 */
final class SupplierController
{
    public function index(Request $request): Response
    {
        return View::render('admin/suppliers/index', [
            'title'      => 'Tedarikçiler',
            'suppliers'  => $this->listWithCounts(),
            'editId'     => is_numeric($request->input('edit')) ? (int) $request->input('edit') : null,
            'message'    => get_flash('supplier_message'),
            'error'      => get_flash('supplier_error'),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($data === null) {
            return redirect('/admin/suppliers');
        }

        $repo = new SupplierRepository();
        if ($repo->findByCode($data['code']) !== null) {
            flash('supplier_error', 'Bu tedarikçi kodu zaten kullanılıyor: ' . $data['code']);
            return redirect('/admin/suppliers');
        }

        try {
            $integration = $this->integrationData($request, null);
            $id = $repo->insert($data + $integration + ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            flash('supplier_error', 'Tedarikçi kaydedilemedi: ' . $e->getMessage());
            return redirect('/admin/suppliers');
        }

        $this->audit($request, 'supplier.created', 'supplier', $id, ['name' => $data['name'], 'code' => $data['code']]);
        flash('supplier_message', 'Tedarikçi eklendi. Şimdi XML kaynağı oluşturabilirsiniz.');
        return redirect('/admin/suppliers');
    }

    /**
     * Düzenleme formu (aynı index görünümü, ?edit=ID parametresiyle açılır).
     */
    public function edit(Request $request, string $id): Response
    {
        return redirect('/admin/suppliers?edit=' . (int) $id);
    }

    public function update(Request $request, string $id): Response
    {
        $repo = new SupplierRepository();
        $supplierId = (int) $id;
        if ($repo->find($supplierId) === null) {
            flash('supplier_error', 'Tedarikçi bulunamadı.');
            return redirect('/admin/suppliers');
        }

        $data = $this->validated($request);
        if ($data === null) {
            return redirect('/admin/suppliers?edit=' . $supplierId);
        }

        $existing = $repo->findByCode($data['code']);
        if ($existing !== null && (int) $existing['id'] !== $supplierId) {
            flash('supplier_error', 'Bu tedarikçi kodu başka bir kayıtta kullanılıyor: ' . $data['code']);
            return redirect('/admin/suppliers?edit=' . $supplierId);
        }

        try {
            $existingRow = $repo->find($supplierId) ?? [];
            $integration = $this->integrationData($request, $existingRow);
            $repo->update($supplierId, $data + $integration + ['updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            flash('supplier_error', 'Güncelleme başarısız: ' . $e->getMessage());
            return redirect('/admin/suppliers?edit=' . $supplierId);
        }

        $this->audit($request, 'supplier.updated', 'supplier', $supplierId, ['name' => $data['name']]);
        flash('supplier_message', 'Tedarikçi güncellendi.');
        return redirect('/admin/suppliers');
    }

    public function toggle(Request $request, string $id): Response
    {
        $repo = new SupplierRepository();
        $supplier = $repo->find((int) $id);
        if ($supplier === null) {
            flash('supplier_error', 'Tedarikçi bulunamadı.');
            return redirect('/admin/suppliers');
        }
        $active = ((int) $supplier['is_active']) === 1;
        $repo->update((int) $id, ['is_active' => $active ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit($request, 'supplier.toggled', 'supplier', (int) $id, ['is_active' => $active ? 0 : 1]);
        flash('supplier_message', $active ? 'Tedarikçi pasife alındı.' : 'Tedarikçi aktifleştirildi.');
        return redirect('/admin/suppliers');
    }

    public function destroy(Request $request, string $id): Response
    {
        $repo = new SupplierRepository();
        $supplierId = (int) $id;
        $supplier = $repo->find($supplierId);
        if ($supplier === null) {
            flash('supplier_error', 'Tedarikçi bulunamadı.');
            return redirect('/admin/suppliers');
        }

        try {
            $repo->delete($supplierId);
            $this->audit($request, 'supplier.deleted', 'supplier', $supplierId, ['name' => $supplier['name']]);
            flash('supplier_message', 'Tedarikçi silindi.');
        } catch (\Throwable $e) {
            // FK RESTRICT: bağlı feed/ürün varsa silinemez
            flash('supplier_error', 'Tedarikçi silinemedi: bağlı XML kaynakları veya ürün verileri var. Önce pasife alın.');
        }
        return redirect('/admin/suppliers');
    }

    /* --------------------------------------------------------------------- */

    /** @return array<string,mixed>|null */
    private function validated(Request $request): ?array
    {
        $validator = Validator::make($request->post(), [
            'name'  => 'required|max:120',
            'code'  => 'required|max:40',
            'email' => 'max:180',
            'phone' => 'max:40',
            'contact_name' => 'max:120',
        ]);
        try {
            $validator->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash('supplier_error', $e->firstError());
            return null;
        }

        $code = strtoupper(trim($request->string('code')));
        if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) {
            flash('supplier_error', 'Tedarikçi kodu 2-40 karakter, yalnız harf/rakam/-/_ olmalıdır.');
            return null;
        }

        return [
            'name'         => trim($request->string('name')),
            'code'         => $code,
            'contact_name' => trim($request->string('contact_name')) !== '' ? trim($request->string('contact_name')) : null,
            'email'        => trim($request->string('email')) !== '' ? trim($request->string('email')) : null,
            'phone'        => trim($request->string('phone')) !== '' ? trim($request->string('phone')) : null,
            'notes'        => trim((string) $request->input('notes', '')) !== '' ? (string) $request->input('notes') : null,
            'is_active'    => $request->input('is_active') !== null ? 1 : 0,
        ];
    }

    /**
     * AŞAMA 5 — API entegrasyon alanları.
     *
     * Güvenlik: API anahtar/parolası ASLA düz metin saklanmaz; APP_KEY ile
     * (Cipher AES-256-GCM) şifrelenir ve görünüme ASLA geri gönderilmez.
     * Alanlar boş bırakılırsa mevcut değerler korunur (write-only form).
     *
     * @param array<string,mixed> $existing
     * @return array{integration_type:string, api_endpoint:?string, api_auth:?string, api_credentials_enc:?string}
     */
    private function integrationData(Request $request, array $existing): array
    {
        $type = $request->input('integration_type') === 'api' ? 'api' : 'manual';
        $endpoint = trim((string) $request->input('api_endpoint', ''));
        $auth     = (string) ($request->input('api_auth', 'none'));
        if (!in_array($auth, ['none', 'bearer', 'basic', 'header'], true)) {
            $auth = 'none';
        }
        $apiKey    = trim((string) $request->input('api_key', ''));
        $apiSecret = trim((string) $request->input('api_secret', ''));
        $headerName = trim((string) $request->input('api_header_name', '')) ?: 'X-API-Key';

        if ($type !== 'api') {
            // manuel modda uç/bilgiler temizlenir
            return ['integration_type' => 'manual', 'api_endpoint' => null, 'api_auth' => null, 'api_credentials_enc' => null];
        }

        if ($endpoint !== '' && !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            flash('supplier_error', 'API endpoint geçerli bir URL olmalıdır (https://…).');
            throw new \RuntimeException('Geçersiz API endpoint');
        }
        if ($endpoint !== '' && !preg_match('#^https://#i', $endpoint)) {
            flash('supplier_error', 'API endpoint yalnızca https:// olabilir (kimlik bilgisi sızması önlenir).');
            throw new \RuntimeException('Geçersiz API endpoint');
        }

        $encrypted = null;
        if ($apiKey !== '' || $apiSecret !== '') {
            // Yeni kimlik bilgileri girildi → şifreleyip sakla
            $encrypted = Cipher::encrypt(json_encode([
                'api_key'     => $apiKey,
                'api_secret'  => $apiSecret,
                'header_name' => $headerName,
            ], JSON_UNESCAPED_UNICODE));
        } else {
            // Boş bırakıldı → mevcut şifreli değer korunur
            $encrypted = isset($existing['api_credentials_enc']) && $existing['api_credentials_enc'] !== null
                ? (string) $existing['api_credentials_enc']
                : null;
        }

        return [
            'integration_type'    => 'api',
            'api_endpoint'        => $endpoint !== '' ? $endpoint : null,
            'api_auth'            => $auth,
            'api_credentials_enc' => $encrypted,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function listWithCounts(): array
    {
        try {
            $pdo = \App\Core\Database::pdo();
            $stmt = $pdo->prepare(
                'SELECT s.*,
                        (SELECT COUNT(*) FROM xml_feeds f WHERE f.supplier_id = s.id) AS feed_count,
                        (SELECT COUNT(*) FROM product_supplier_data p WHERE p.supplier_id = s.id) AS product_count
                 FROM suppliers s ORDER BY s.id DESC'
            );
            $stmt->execute();
            /** @var list<array<string,mixed>> $rows */
            $rows = $stmt->fetchAll();
            return $rows;
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
