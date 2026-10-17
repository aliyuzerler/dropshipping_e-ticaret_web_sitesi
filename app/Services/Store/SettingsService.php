<?php

declare(strict_types=1);

namespace App\Services\Store;

use App\Repositories\SettingRepository;

/**
 * Mağaza ayarları (settings tablosu + kod varsayılanları).
 * Hukuki metinler config/legal_texts.php TASLAK'larından gelir; admin
 * SettingsService::set ile üzerine yazar. İşletme bilgisi yer tutucuları
 * render anında değiştirilir.
 *
 * Tam yol: app/Services/Store/SettingsService.php
 */
final class SettingsService
{
    public const DEFAULTS = [
        'store.name'                    => 'XML Mağaza',
        'store.company_title'           => 'ÖRNEK TİCARET A.Ş. (TASLAK)',
        'store.contact_email'           => 'info@example.local',
        'store.contact_phone'           => '+90 850 000 00 00',
        'store.contact_address'         => 'Örnek Mah. Sanayi Cad. No:1, 34000 İstanbul',
        'store.shipping_fee'            => '49.90',
        'store.free_shipping_threshold' => '750.00',
        'store.stale_stock_hours'       => '48',
        'store.stale_stock_action'      => 'disable', // disable | hide
    ];

    private ?array $cache = null;

    private SettingRepository $repo;

    public function __construct(?SettingRepository $repo = null)
    {
        $this->repo = $repo ?? new SettingRepository();
    }

    /** @return array<string,string> varsayılanlar + DB değerleri */
    public function all(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = [...self::DEFAULTS, ...$this->repo->allSettings()];
            } catch (\Throwable) {
                $this->cache = self::DEFAULTS; // ayarlar tablosu yoksa / DB hatasında çalışmaya devam
            }
        }
        return $this->cache;
    }

    public function get(string $key, ?string $default = null): string
    {
        $all = $this->all();
        return (string) ($all[$key] ?? $default ?? '');
    }

    public function set(string $key, ?string $value): void
    {
        $this->repo->set($key, $value);
        $this->cache = null;
    }

    /* ----- Tipli erişimler ----- */

    public function storeName(): string
    {
        return $this->get('store.name', 'XML Mağaza');
    }

    public function companyTitle(): string
    {
        return $this->get('store.company_title', '');
    }

    public function contactEmail(): string
    {
        return $this->get('store.contact_email', '');
    }

    public function contactPhone(): string
    {
        return $this->get('store.contact_phone', '');
    }

    public function contactAddress(): string
    {
        return $this->get('store.contact_address', '');
    }

    public function shippingFee(): float
    {
        return (float) $this->get('store.shipping_fee', '49.90');
    }

    public function freeShippingThreshold(): float
    {
        return (float) $this->get('store.free_shipping_threshold', '750.00');
    }

    /** Tedarikçi stok bilgisi kaç saatten eskiyse "bayat" sayılır. */
    public function staleStockHours(): int
    {
        return max(1, (int) $this->get('store.stale_stock_hours', '48'));
    }

    /** Bayat stokta davranış: 'disable' (satın alma kapalı) | 'hide' (listelerde gizli). */
    public function staleStockAction(): string
    {
        return $this->get('store.stale_stock_action', 'disable') === 'hide' ? 'hide' : 'disable';
    }

    /* ----- Hukuki metinler (TASLAK) ----- */

    /**
     * Kayıtlı metin yoksa config/legal_texts.php TASLAĞI döner.
     * Yer tutucular işletme bilgileriyle değiştirilir.
     */
    public function legal(string $key): string
    {
        $stored = $this->get($key, '');
        // Config dosyaları dosya adıyla öneklenir: config/legal_texts.php → 'legal_texts' kökü
        // (anahtarlar 'legal.privacy' gibi NOKTALI olduğundan kökten alınıp elle indekslenir)
        $defaults = (array) (config('legal_texts', []) ?? []);
        $text = $stored !== '' ? $stored : (string) ($defaults[$key] ?? '');
        return $this->interpolate($text);
    }

    /** @return array<string,string> */
    public function legalPlaceholders(): array
    {
        return [
            '{{STORE_NAME}}'   => $this->storeName(),
            '{{COMPANY_TITLE}}' => $this->companyTitle(),
            '{{EMAIL}}'        => $this->contactEmail(),
            '{{PHONE}}'        => $this->contactPhone(),
            '{{ADDRESS}}'      => $this->contactAddress(),
            '{{DATE}}'         => date('d.m.Y'),
        ];
    }

    private function interpolate(string $text): string
    {
        return strtr($text, $this->legalPlaceholders());
    }
}
