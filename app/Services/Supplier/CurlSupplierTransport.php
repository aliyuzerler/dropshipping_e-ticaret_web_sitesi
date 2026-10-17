<?php

declare(strict_types=1);

namespace App\Services\Supplier;

/**
 * AŞAMA 5 — curl tabanlı tedarikçi API transportu (TLS doğrulamalı).
 *
 * Tam yol: app/Services/Supplier/CurlSupplierTransport.php
 */
final class CurlSupplierTransport implements SupplierTransport
{
    public function request(string $method, string $url, array $headers, string $body, float $timeoutSec): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl başlatılamadı.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) max(2000, $timeoutSec * 1000),
            CURLOPT_TIMEOUT_MS     => (int) max(3000, $timeoutSec * 1000),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $errno  = curl_errno($ch);
            $errstr = (string) curl_error($ch);
            curl_close($ch);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new SupplierTransportTimeoutException('Tedarikçi API zaman aşımı: ' . $errstr);
            }
            throw new \RuntimeException('Tedarikçi API hatası (' . $errno . '): ' . $errstr);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $response];
    }
}
