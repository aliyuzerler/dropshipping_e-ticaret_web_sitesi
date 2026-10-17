<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Store\SettingsService;

/**
 * Hukuki / kurumsal statik sayfalar. Metinler SettingsService::legal()
 * üzerinden gelir (settings tablosu → config/legal_texts.php TASLAĞI) ve
 * HTML olarak GÜVENİLİR kabul edilir → view'da ham basılır (e() YAPILMAZ).
 *
 * Tam yol: app/Controllers/Store/PageController.php
 */
final class PageController
{
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        $this->settings = $settings ?? new SettingsService();
    }

    public function privacy(Request $request): Response
    {
        return $this->legalPage(
            'legal.privacy',
            'Gizlilik ve KVKK Aydınlatma Metni',
            'store/pages/privacy',
            'Kişisel verilerinizin korunması, KVKK aydınlatma metni ve gizlilik politikamız.'
        );
    }

    public function distanceSales(Request $request): Response
    {
        return $this->legalPage(
            'legal.distance_sales',
            'Mesafeli Satış Sözleşmesi',
            'store/pages/distance_sales',
            'Uzaktan alışverişe ilişkin mesafeli satış sözleşmesi koşulları.'
        );
    }

    public function preInformation(Request $request): Response
    {
        return $this->legalPage(
            'legal.preinformation',
            'Ön Bilgilendirme Formu',
            'store/pages/preinformation',
            'Sipariş öncesinde tüketiciye sunulan ön bilgilendirme formu.'
        );
    }

    public function returnsDelivery(Request $request): Response
    {
        return $this->legalPage(
            'legal.returns_delivery',
            'İade ve Teslimat',
            'store/pages/returns',
            'İade ve cayma hakkı koşulları ile teslimat bilgileri.'
        );
    }

    /* -----------------------------------------------------------------
     * İç yardımcılar
     * ----------------------------------------------------------------- */

    private function legalPage(string $settingKey, string $title, string $template, string $metaDescription): Response
    {
        return View::render($template, [
            'title'           => $title,
            'metaDescription' => $metaDescription,
            'contentHtml'     => $this->settings->legal($settingKey),
        ], 'layouts/store');
    }
}
