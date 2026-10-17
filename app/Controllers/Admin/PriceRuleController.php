<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\PriceRuleRepository;
use App\Repositories\SupplierRepository;
use App\Services\Audit\AuditLogger;
use App\Validators\Validator;

/**
 * Fiyatlandırma kuralları yönetimi.
 *
 *  GET  /admin/price-rules          → liste + ekleme formu
 *  POST /admin/price-rules          → kural ekle
 *  POST /admin/price-rules/{id}/toggle → aktif/pasif
 *  POST /admin/price-rules/{id}/delete → sil
 *
 * Tam yol: app/Controllers/Admin/PriceRuleController.php
 */
final class PriceRuleController
{
    public const TYPES = [
        'percent'   => 'Yüzde kâr artışı (%)',
        'fixed'     => 'Sabit tutar ekle (TRY)',
        'min_price' => 'Minimum satış fiyatı (TRY)',
        'round'     => 'Yuvarlama adımı (örn. 1, 0.5)',
        'round_end' => 'Psikolojik fiyat sonu (örn. 0.99)',
    ];

    public const SCOPES = [
        'global'   => 'Tüm ürünler',
        'supplier' => 'Tedarikçiye göre',
        'category' => 'Kategoriye göre',
        'product'  => 'Ürüne göre',
    ];

    public function index(Request $request): Response
    {
        return View::render('admin/price_rules/index', [
            'title'      => 'Fiyat Kuralları',
            'rules'      => (new PriceRuleRepository())->listWithNames(),
            'types'      => self::TYPES,
            'scopes'     => self::SCOPES,
            'suppliers'  => (new SupplierRepository())->activeSuppliers(),
            'categories' => (new CategoryRepository())->allOrdered(),
            'threshold'  => (float) \App\Core\Config::get('xml.pricing.profit_warn_percent', 10.0),
            'message'    => get_flash('rule_message'),
            'error'      => get_flash('rule_error'),
        ]);
    }

    public function store(Request $request): Response
    {
        $validator = Validator::make($request->post(), [
            'name'     => 'required|max:150',
            'type'     => 'required|in:percent,fixed,min_price,round,round_end',
            'scope'    => 'required|in:global,supplier,category,product',
            'value'    => 'required|numeric',
            'priority' => 'int',
        ]);

        try {
            $validator->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash('rule_error', $e->firstError());
            return redirect('/admin/price-rules');
        }

        $scope = $request->string('scope');
        $supplierId = $this->optionalInt($request, 'supplier_id');
        $categoryId = $this->optionalInt($request, 'category_id');
        $productId = $this->optionalInt($request, 'product_id');

        if ($scope === 'supplier' && $supplierId === null) {
            flash('rule_error', 'Tedarikçi kapsamı için tedarikçi seçin.');
            return redirect('/admin/price-rules');
        }
        if ($scope === 'category' && $categoryId === null) {
            flash('rule_error', 'Kategori kapsamı için kategori seçin.');
            return redirect('/admin/price-rules');
        }
        if ($scope === 'product' && $productId === null) {
            flash('rule_error', 'Ürün kapsamı için ürün ID girin.');
            return redirect('/admin/price-rules');
        }

        $value = (float) $request->string('value');
        if (in_array($request->string('type'), ['percent', 'round'], true) && $value < 0) {
            flash('rule_error', 'Yüzde ve yuvarlama değerleri negatif olamaz.');
            return redirect('/admin/price-rules');
        }

        $repo = new PriceRuleRepository();
        try {
            $id = $repo->insert([
                'name'        => trim($request->string('name')),
                'type'        => $request->string('type'),
                'scope'       => $scope,
                'product_id'  => $productId,
                'category_id' => $categoryId,
                'variant_id'  => null,
                'supplier_id' => $supplierId,
                'value'       => $value,
                'min_qty'     => 1,
                'currency'    => 'TRY',
                'priority'    => max(0, (int) ($request->string('priority', '0') ?: 0)),
                'is_active'   => $request->input('is_active') !== null ? 1 : 0,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            flash('rule_error', 'Kural kaydedilemedi: ' . $e->getMessage());
            return redirect('/admin/price-rules');
        }

        AuditLogger::log(
            action: 'price_rule.created',
            adminUserId: Session::get('admin_id') !== null ? (int) Session::get('admin_id') : null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            entityType: 'price_rule',
            entityId: $id,
            context: ['type' => $request->string('type'), 'scope' => $scope, 'value' => $value]
        );

        flash('rule_message', 'Fiyat kuralı eklendi. Sonraki senkronizasyondan geçerli olur.');
        return redirect('/admin/price-rules');
    }

    public function toggle(Request $request, string $id): Response
    {
        $repo = new PriceRuleRepository();
        $rule = $repo->find((int) $id);
        if ($rule === null) {
            flash('rule_error', 'Kural bulunamadı.');
            return redirect('/admin/price-rules');
        }
        $repo->update((int) $id, [
            'is_active'  => ((int) $rule['is_active']) === 1 ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        flash('rule_message', 'Kural durumu güncellendi.');
        return redirect('/admin/price-rules');
    }

    public function destroy(Request $request, string $id): Response
    {
        (new PriceRuleRepository())->delete((int) $id);
        AuditLogger::log(
            action: 'price_rule.deleted',
            adminUserId: Session::get('admin_id') !== null ? (int) Session::get('admin_id') : null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            entityType: 'price_rule',
            entityId: (int) $id
        );
        flash('rule_message', 'Kural silindi.');
        return redirect('/admin/price-rules');
    }

    private function optionalInt(Request $request, string $key): ?int
    {
        $raw = trim($request->string($key, ''));
        return $raw !== '' && is_numeric($raw) ? (int) $raw : null;
    }
}
