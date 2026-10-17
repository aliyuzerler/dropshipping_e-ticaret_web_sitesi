<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Repositories\SupplierRepository;
use App\Services\Security\Cipher;

/**
 * AŞAMA 5 — Tedarikçi adaptörü üreticisi.
 *
 * Kural (görev gereği): suppliers.integration_type = 'api' VE gerçek uç +
 * kimlik bilgisi girilmişse ApiSupplierAdapter; aksi her hâlde
 * ManualSupplierAdapter. API bilgisi olmayan tedarikçi için sahte otomatik
 * entegrasyon ÜRETİLMEZ — manuel görev olarak yönetilir.
 *
 * Tam yol: app/Services/Supplier/SupplierAdapterFactory.php
 */
final class SupplierAdapterFactory
{
    private ?SupplierRepository $suppliers;

    private ?SupplierTransport $transport;

    /**
     * @param SupplierTransport|null $transport yalnız test enjeksiyonu için
     *        (canlıda null → curl transport kullanılır)
     */
    public function __construct(?SupplierRepository $suppliers = null, ?SupplierTransport $transport = null)
    {
        $this->suppliers = $suppliers ?? new SupplierRepository();
        $this->transport = $transport;
    }

    /** Tedarikçi satırına göre uygun adaptörü döndürür. */
    public function forSupplier(array $supplier): SupplierAdapterInterface
    {
        if ($this->suppliers->hasApiConfig($supplier)) {
            $credentials = $this->decodeCredentials((string) $supplier['api_credentials_enc']);
            return new ApiSupplierAdapter([
                'endpoint'   => (string) $supplier['api_endpoint'],
                'auth'       => (string) ($supplier['api_auth'] ?? 'none'),
                'api_key'    => (string) ($credentials['api_key'] ?? ''),
                'api_secret' => (string) ($credentials['api_secret'] ?? ''),
                'header_name' => $credentials['header_name'] ?? null,
                'timeout_sec' => isset($credentials['timeout']) ? (float) $credentials['timeout'] : null,
            ], $this->transport);
        }
        return new ManualSupplierAdapter();
    }

    /** Testler için serbest kurulum. */
    public static function manual(): SupplierAdapterInterface
    {
        return new ManualSupplierAdapter();
    }

    /**
     * Şifreli kimlik bilgilerini çözer. Bozuk/eksik veri güvenli biçimde boş döner
     * (adaptör o zaman manual'a düşer — akış kırılmaz, kimlik bilgisi sızmaz).
     *
     * @return array{api_key?:string, api_secret?:string, header_name?:string, timeout?:float}
     */
    private function decodeCredentials(string $encrypted): array
    {
        try {
            $json = Cipher::decrypt($encrypted);
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
