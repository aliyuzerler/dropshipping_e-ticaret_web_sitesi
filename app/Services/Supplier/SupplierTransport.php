<?php

declare(strict_types=1);

namespace App\Services\Supplier;

/**
 * AŞAMA 5 — HTTP transport sözleşmesi + zaman aşımı istisnası.
 *
 * API adaptörü ağ I/O'sunu transport'a delege eder; testler sahte transport
 * vererek başarılı/hatalı/zaman aşımı senaryolarını deterministik koşar.
 *
 * Tam yol: app/Services/Supplier/SupplierTransport.php
 */
interface SupplierTransport
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     * @throws SupplierTransportTimeoutException bağlantı/yanıt zaman aşımında
     * @throws \RuntimeException                 diğer ağ hatalarında
     */
    public function request(string $method, string $url, array $headers, string $body, float $timeoutSec): array;
}

/** Zaman aşımı — gönderimin karşı tarafa ulaşılıp ulaşmadığı BELİRSİZDIR. */
final class SupplierTransportTimeoutException extends \RuntimeException
{
}
