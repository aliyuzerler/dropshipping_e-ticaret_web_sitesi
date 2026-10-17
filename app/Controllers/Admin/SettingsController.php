<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exceptions\ValidationException;
use App\Services\Audit\AuditLogger;
use App\Services\Store\SettingsService;

/**
 * Mağaza ayarları (Aşama 3): işletme bilgisi, kargo, eski stok kuralı ve
 * hukuki metin TASLAKLARININ düzenlenmesi.
 *
 * Tam yol: app/Controllers/Admin/SettingsController.php
 */
final class SettingsController
{
    /** Düzenlenebilir düz metin anahtarları. @var list<string> */
    private const SIMPLE_KEYS = [
        'store.name',
        'store.company_title',
        'store.contact_email',
        'store.contact_phone',
        'store.contact_address',
        'store.shipping_fee',
        'store.free_shipping_threshold',
        'store.stale_stock_hours',
        'store.stale_stock_action',
    ];

    /** Hukuki metin anahtarları (TASLAK). @var list<string> */
    private const LEGAL_KEYS = [
        'legal.privacy',
        'legal.distance_sales',
        'legal.preinformation',
        'legal.returns_delivery',
    ];

    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    public function index(Request $request): Response
    {
        return View::render('admin/settings', [
            'title'    => 'Mağaza Ayarları',
            'active'   => 'settings',
            'values'   => $this->settings->all(),
        ]);
    }

    public function save(Request $request): Response
    {
        $errors = [];
        $updates = [];

        foreach (self::SIMPLE_KEYS as $key) {
            $field = str_replace('.', '_', $key);
            $value = trim((string) $request->input($field, ''));
            $updates[$key] = $value;
        }

        // Sayısal doğrulamalar
        if ($updates['store.name'] === '') {
            $errors['store_name'] = ['Mağaza adı zorunludur.'];
        }
        if (!is_numeric($updates['store.shipping_fee']) || (float) $updates['store.shipping_fee'] < 0) {
            $errors['store_shipping_fee'] = ['Kargo ücreti geçerli bir sayı olmalıdır.'];
        }
        if (!is_numeric($updates['store.free_shipping_threshold']) || (float) $updates['store.free_shipping_threshold'] < 0) {
            $errors['store_free_shipping_threshold'] = ['Ücretsiz kargo eşiği geçerli bir sayı olmalıdır.'];
        }
        if (!ctype_digit($updates['store.stale_stock_hours']) || (int) $updates['store.stale_stock_hours'] < 1) {
            $errors['store_stale_stock_hours'] = ['Eskime eşiği pozitif tam sayı (saat) olmalıdır.'];
        }
        if (!in_array($updates['store.stale_stock_action'], ['disable', 'hide'], true)) {
            $errors['store_stale_stock_action'] = ['Eskime davranışı "disable" veya "hide" olmalıdır.'];
        }
        if ($updates['store.contact_email'] !== '' && !filter_var($updates['store.contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['store_contact_email'] = ['Geçerli bir e-posta girin.'];
        }

        // Hukuki metinler (boş bırakılırsa TASLAK default kullanılır)
        foreach (self::LEGAL_KEYS as $key) {
            $field = str_replace('.', '_', $key);
            $updates[$key] = (string) $request->input($field, '');
        }

        if ($errors !== []) {
            flash('error', implode(' ', array_merge(...array_values($errors))));
            return redirect('/admin/settings');
        }

        foreach ($updates as $key => $value) {
            $this->settings->set($key, $value);
        }

        AuditLogger::log(
            action: 'admin.settings_updated',
            adminUserId: is_int(\App\Core\Session::get('admin_id')) ? \App\Core\Session::get('admin_id') : null,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            context: ['keys' => array_keys($updates)]
        );

        flash('success', 'Mağaza ayarları kaydedildi.');
        return redirect('/admin/settings');
    }
}
