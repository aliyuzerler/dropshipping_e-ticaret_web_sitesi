<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * iyzico API istemcisi sözleşmesi (POST JSON istekleri).
 * Gerçek: IyzipayClient (curl + IYZWSv2). Test/simülasyon: IyzipayMockClient.
 *
 * Tam yol: app/Services/Payment/IyzipayClientInterface.php
 */
interface IyzipayClientInterface
{
    /**
     * Verilen iyzico uç noktasına POST/JSON isteği atar ve çözümlenmiş yanıtı döndürür.
     *
     * @param string $uri  ör. /payment/iyzipos/checkoutform/initialize/auth/ecom
     * @param array<string,mixed> $body JSON gövde
     * @return array<string,mixed> çözümlenmiş JSON yanıt
     * @throws IyzipayException ağ/HTTP hatasında
     */
    public function request(string $uri, array $body): array;

    /** mock istemci mi? (görünüm/simülasyon kararları için) */
    public function isMock(): bool;
}
