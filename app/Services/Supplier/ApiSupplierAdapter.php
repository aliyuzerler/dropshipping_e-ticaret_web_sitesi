<?php

declare(strict_types=1);

namespace App\Services\Supplier;

/**
 * AŞAMA 5 — Genel tedarikçi API adaptörü.
 *
 * YALNIZCA yöneticinin gerçek uç + kimlik bilgisi yapılandırdığı tedarikçiler
 * için kullanılır (suppliers.integration_type = 'api'). Sözleşme:
 *
 *   POST {endpoint}                          → sipariş gönderimi
 *       Gövde: {idempotency_key, order_number, currency, items[], delivery}
 *       Başlık: Idempotency-Key: {idempotency_key} (tekrarlı gönderim koruması)
 *       Beklenen 2xx yanıtı: {"reference": "...", "status": "accepted"}
 *   GET  {endpoint}/{external_reference}     → durum sorgusu
 *       Beklenen: {"status": "confirmed|approved|processing|pending|not_found"}
 *   GET  {endpoint}/{external_reference}/tracking → takip bilgisi
 *       Beklenen: {"carrier", "tracking_number", "tracking_url", "status"}
 *
 * Farklı tedarikçiler bu şemadan sapıyorsa SAHTE entegrasyon üretilmez; alan
 * eşlemesi bu adaptöre tedarikçiye özel küçük bir alt sınıf/transport ile
 * eklenir (gerçek API belgeleri görülmeden yazılmaz).
 *
 * Kimlik bilgileri Cipher (APP_KEY AES-256-GCM) ile şifreli tutulur; yalnızca
 * sunucu tarafında çözülür, ASLA görünüme/günlüğe yazılmaz.
 *
 * Tam yol: app/Services/Supplier/ApiSupplierAdapter.php
 */
final class ApiSupplierAdapter implements SupplierAdapterInterface
{
    /** @param array{endpoint:string, auth:string, api_key:string, api_secret:string, header_name?:string, timeout_sec?:float} $config */
    public function __construct(
        private array $config,
        private ?SupplierTransport $transport = null
    ) {
        $this->transport = $transport ?? new CurlSupplierTransport();
    }

    public function supportsApi(): bool
    {
        return true;
    }

    public function sendOrder(array $supplierOrder, array $items, array $context): array
    {
        $internalRef = (string) ($supplierOrder['internal_reference'] ?? '');
        if ($internalRef === '') {
            return ['ok' => false, 'external_reference' => null, 'message' => 'Dahili referans yok — gönderim reddedildi.', 'timeout' => false, 'raw' => null];
        }

        $payload = [
            'idempotency_key' => $internalRef,
            'order_number'    => (string) ($context['order_number'] ?? ''),
            'currency'        => (string) ($context['currency'] ?? 'TRY'),
            'placed_at'       => (string) ($context['placed_at'] ?? ''),
            'delivery'        => (array) ($context['delivery'] ?? []),
            'note'            => (string) ($context['note'] ?? ''),
            'items'           => array_map(static fn (array $it): array => [
                'sku'        => (string) ($it['supplier_sku'] ?? $it['sku_snapshot'] ?? ''),
                'quantity'   => (int) $it['quantity'],
                'unit_cost'  => (float) $it['unit_cost'],
                'line_total' => (float) $it['line_total'],
            ], $items),
        ];

        $headers = $this->authHeaders();
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';
        $headers[] = 'Idempotency-Key: ' . $internalRef;

        try {
            $res = $this->transport->request('POST', $this->config['endpoint'], $headers, json_encode($payload, JSON_UNESCAPED_UNICODE), $this->timeout());
        } catch (SupplierTransportTimeoutException $e) {
            // Karşı taraf siparişi ALDI mi bilinemez → çağıran 'unknown' durumuna geçer.
            return ['ok' => false, 'external_reference' => null, 'message' => $e->getMessage(), 'timeout' => true, 'raw' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'external_reference' => null, 'message' => $e->getMessage(), 'timeout' => false, 'raw' => null];
        }

        $body = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && is_array($body)) {
            $ref = $body['reference'] ?? $body['external_reference'] ?? $body['order_id'] ?? null;
            return [
                'ok'                 => true,
                'external_reference' => $ref !== null ? substr((string) $ref, 0, 100) : null,
                'message'            => (string) ($body['message'] ?? 'Tedarikçi siparişi kabul edildi.'),
                'timeout'            => false,
                'raw'                => $this->safeRaw($body),
            ];
        }

        return [
            'ok'                 => false,
            'external_reference' => null,
            'message'            => 'Tedarikçi API yanıtı başarısız (HTTP ' . $res['status'] . '): ' . mb_substr($res['body'], 0, 200, 'UTF-8'),
            'timeout'            => false,
            'raw'                => is_array($body) ? $this->safeRaw($body) : null,
        ];
    }

    public function queryStatus(array $supplierOrder): array
    {
        // Dış referans (yanıt) varsa onunla; yoksa (zaman aşımında yanıt alınamadıysa)
        // idempotency anahtarı = DAHİLİ referans ile sorgulanır. Kayıtsız sorgu yapılmaz.
        $external = (string) ($supplierOrder['external_reference'] ?? '');
        $internal = (string) ($supplierOrder['internal_reference'] ?? '');
        $ref = $external !== '' ? $external : $internal;
        if ($ref === '') {
            return ['ok' => false, 'confirmed' => false, 'found' => false, 'message' => 'Sorgu referansı yok.', 'external_reference' => null];
        }

        $url = rtrim($this->config['endpoint'], '/') . '/' . rawurlencode($ref);
        try {
            $res = $this->transport->request('GET', $url, $this->authHeaders(), '', $this->timeout());
        } catch (SupplierTransportTimeoutException $e) {
            return ['ok' => false, 'confirmed' => false, 'found' => false, 'message' => $e->getMessage(), 'external_reference' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'confirmed' => false, 'found' => false, 'message' => $e->getMessage(), 'external_reference' => null];
        }

        $body = json_decode($res['body'], true);
        $status = is_array($body) ? mb_strtolower((string) ($body['status'] ?? $body['state'] ?? '')) : '';

        if ($res['status'] === 404 || $status === 'not_found') {
            return ['ok' => true, 'confirmed' => false, 'found' => false, 'message' => 'Tedarikçide kayıt bulunamadı.', 'external_reference' => $external];
        }
        if ($res['status'] >= 200 && $res['status'] < 300) {
            $confirmed = in_array($status, ['confirmed', 'approved', 'accepted', 'completed', 'success'], true);
            return [
                'ok'                 => true,
                'confirmed'          => $confirmed,
                'found'              => true,
                'message'            => $status !== '' ? 'Tedarikçi durumu: ' . $status : 'Tedarikçi yanıt verdi.',
                'external_reference' => $external,
            ];
        }

        return ['ok' => false, 'confirmed' => false, 'found' => false, 'message' => 'Sorgu hatası (HTTP ' . $res['status'] . ')', 'external_reference' => $external];
    }

    public function fetchTracking(array $supplierOrder): array
    {
        $external = (string) ($supplierOrder['external_reference'] ?? '');
        $ref = $external !== '' ? $external : (string) ($supplierOrder['internal_reference'] ?? '');
        if ($ref === '') {
            return ['ok' => false, 'carrier' => null, 'tracking_number' => null, 'tracking_url' => null, 'message' => 'Sorgu referansı yok.'];
        }

        $url = rtrim($this->config['endpoint'], '/') . '/' . rawurlencode($ref) . '/tracking';
        try {
            $res = $this->transport->request('GET', $url, $this->authHeaders(), '', $this->timeout());
        } catch (\Throwable $e) {
            return ['ok' => false, 'carrier' => null, 'tracking_number' => null, 'tracking_url' => null, 'message' => $e->getMessage()];
        }

        $body = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300 && is_array($body)) {
            return [
                'ok'              => true,
                'carrier'         => $body['carrier'] ?? null,
                'tracking_number' => $body['tracking_number'] ?? null,
                'tracking_url'    => $body['tracking_url'] ?? null,
                'message'         => 'Takip bilgisi alındı.',
            ];
        }
        return ['ok' => false, 'carrier' => null, 'tracking_number' => null, 'tracking_url' => null, 'message' => 'Takip sorgusu başarısız (HTTP ' . $res['status'] . ')'];
    }

    /** @return list<string> */
    private function authHeaders(): array
    {
        $auth = (string) ($this->config['auth'] ?? 'none');
        $key  = (string) ($this->config['api_key'] ?? '');
        $sec  = (string) ($this->config['api_secret'] ?? '');

        return match ($auth) {
            'bearer' => ['Authorization: Bearer ' . ($sec !== '' ? $sec : $key)],
            'basic'  => ['Authorization: Basic ' . base64_encode($key . ':' . $sec)],
            'header' => [substr((string) ($this->config['header_name'] ?? 'X-API-Key'), 0, 60) . ': ' . $key],
            default  => [],
        };
    }

    private function timeout(): float
    {
        $configured = (float) ($this->config['timeout_sec'] ?? 0);
        if ($configured <= 0) {
            $configured = (float) env('SUPPLIER_SEND_TIMEOUT', '15');
        }
        return max(3.0, $configured);
    }

    /** Yanıtın güvenli alt kümesi (kimlik bilgileri/kart verisi asla saklanmaz). */
    private function safeRaw(array $body): array
    {
        return [
            'status'  => $body['status'] ?? null,
            'message' => $body['message'] ?? null,
            'reference' => $body['reference'] ?? $body['external_reference'] ?? $body['order_id'] ?? null,
        ];
    }
}
