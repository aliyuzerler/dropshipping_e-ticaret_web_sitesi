<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Gerçek iyzico API istemcisi (resmî dokümantasyon + iyzipay-php 2.0.x uyumlu):
 *
 * Kimlik doğrulama (HMACSHA256 / IYZWSv2):
 *   randomKey : her istek için üretilen rastgele değer
 *   signature : bin2hex(HMAC-SHA256(randomKey . uriPath . requestBody, secretKey))
 *   header    : "IYZWSv2 " . base64("apiKey:" . apiKey . "&randomKey:" . rnd . "&signature:" . sig)
 *
 * Kaynaklar (resmî):
 *   - docs.iyzico.com/on-hazirliklar/kimlik-dogrulama/hmacsha256-kimlik-dogrulama
 *   - docs.iyzico.com/odeme-metotlari/odeme-formu/cf-entegrasyonu/cf-baslatma
 *   - github.com/iyzico/iyzipay-php → IyziAuthV2Generator
 *
 * Güvenlik: secret key yalnız sunucu tarafında (.env); hata mesajları sızdırmaz;
 * TLS doğrulaması açık (CURLOPT_SSL_VERIFYPEER/Host true).
 *
 * Tam yol: app/Services/Payment/IyzipayClient.php
 */
final class IyzipayClient implements IyzipayClientInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 20
    ) {
    }

    /** Yapılandırmadan istemci üretir (config/payment.php). */
    public static function fromConfig(): self
    {
        return new self(
            (string) config('payment.api_key', ''),
            (string) config('payment.secret_key', ''),
            rtrim((string) config('payment.base_url', 'https://sandbox-api.iyzipay.com'), '/'),
            (int) config('payment.timeout', 20)
        );
    }

    public function isMock(): bool
    {
        return false;
    }

    public function request(string $uri, array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false || $payload === '[]') {
            $payload = '{}';
        }

        $rnd = $this->randomKey();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . $this->authorizationHeader($uri, $payload, $rnd),
        ];

        $ch = curl_init($this->baseUrl . $uri);
        if ($ch === false) {
            throw new IyzipayException('HTTP istemcisi başlatılamadı.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => max(3, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new IyzipayException('iyzico bağlantı hatası: ' . $err);
        }

        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new IyzipayException('iyzico yanıtı çözümlenemedi (HTTP ' . $code . ').');
        }
        $decoded['_http_status'] = $code;
        return $decoded;
    }

    /** IYZWSv2 Authorization başlığı üretir. */
    private function authorizationHeader(string $uri, string $payload, string $rnd): string
    {
        $signature = bin2hex(hash_hmac('sha256', $rnd . $uri . $payload, $this->secretKey, true));
        $authContent = 'apiKey:' . $this->apiKey . '&randomKey:' . $rnd . '&signature:' . $signature;
        return 'IYZWSv2 ' . base64_encode($authContent);
    }

    private function randomKey(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return uniqid('rnd', true);
        }
    }
}
