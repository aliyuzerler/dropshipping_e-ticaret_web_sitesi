<?php

declare(strict_types=1);

namespace App\Services\Payment;

use RuntimeException;

/**
 * iyzico MOCK istemcisi — gerçek iyzico API'sine ÇAĞRI YAPMAZ.
 *
 * Amaç: AŞAMA 4'ün tüm sipariş/ödeme akışını (checkout başlatma, callback,
 * sorgulama, iptal, iade) gerçek API anahtarı olmadan uçtan uca çalıştırmak
 * ve testlerde deterministik sonuç üretmek.
 *
 * Çalışma biçimi:
 *  - initialize → token üretir, istek gövdesini storage/cache/mock-iyzico altına yazar.
 *  - setOutcome → token için senaryo seçer (success|failure|wrong_amount|fraud_review).
 *    (Testler doğrudan çağırır; /odeme/simulasyon ekranı bunu kullanır.)
 *  - retrieve → kayıtlı istekten gerçek API yanıt şemasına birebir uyumlu
 *    sahte sonuç üretir (paymentStatus, paidPrice, itemTransactions...).
 *  - cancel / refund → başarılı yanıt + sahte işlem referansı.
 *
 * GÜVENLİK NOTU: Bu istemci yalnız IYZICO_MODE=mock iken seçilir; canlı ortamda
 * asla devreye girmez. Sunucu tarafı ödeme doğrulaması (PaymentService) mock
 * istemciyle de AYNI kod yolundan geçer — başarı ekranı tek başına kanıt değildir.
 *
 * Tam yol: app/Services/Payment/IyzipayMockClient.php
 */
final class IyzipayMockClient implements IyzipayClientInterface
{
    private const SCENARIOS = ['success', 'failure', 'wrong_amount', 'fraud_review'];

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? rtrim((string) \App\Core\App::storagePath('cache/mock-iyzico'), '/');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
    }

    public function isMock(): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $uri ignored (arayüz uyumu)
     * @param array<string,mixed> $body istek gövdesi
     */
    public function request(string $uri, array $body): array
    {
        return match (true) {
            str_contains($uri, 'checkoutform/initialize') => $this->initialize($body),
            str_contains($uri, 'checkoutform/auth/ecom/detail') => $this->retrieve($body),
            str_contains($uri, '/payment/cancel') => $this->cancel($body),
            str_contains($uri, '/payment/refund') => $this->refund($body),
            default => throw new IyzipayException('Mock istemci bilinmeyen uç nokta: ' . $uri),
        };
    }

    /** Checkout Form başlatma simülasyonu. @param array<string,mixed> $body */
    public function initialize(array $body): array
    {
        $token = $this->uuid();
        $this->write($token, ['request' => $body, 'outcome' => 'success']);
        return [
            'status'              => 'success',
            'locale'              => 'tr',
            'systemTime'          => (int) (microtime(true) * 1000),
            'conversationId'      => (string) ($body['conversationId'] ?? ''),
            'token'               => $token,
            'checkoutFormContent' => '<!-- iyzico mock checkout form (sandbox simülasyon) -->',
            'paymentPageUrl'      => 'mock://checkoutform/' . $token,
        ];
    }

    /** Token için ödeme senaryosunu değiştirir. */
    public function setOutcome(string $token, string $scenario): void
    {
        if (!in_array($scenario, self::SCENARIOS, true)) {
            throw new RuntimeException('Geçersiz mock senaryo: ' . $scenario);
        }
        $record = $this->read($token);
        if ($record === null) {
            throw new IyzipayException('Mock token bulunamadı.');
        }
        $record['outcome'] = $scenario;
        $this->write($token, $record);
    }

    /** CF sorgulama simülasyonu. @param array<string,mixed> $body */
    public function retrieve(array $body): array
    {
        $token  = (string) ($body['token'] ?? '');
        $record = $this->read($token);
        if ($record === null) {
            return [
                'status'       => 'failure',
                'errorCode'    => 'TOKEN_NOT_FOUND',
                'errorMessage' => 'Ödeme formu token\'ı bulunamadı.',
                'locale'       => 'tr',
                'systemTime'   => (int) (microtime(true) * 1000),
            ];
        }

        /** @var array<string,mixed> $req */
        $req      = $record['request'] ?? [];
        $outcome  = (string) ($record['outcome'] ?? 'success');
        $price    = (float) ($req['price'] ?? 0);
        $paid     = (float) ($req['paidPrice'] ?? $price);
        if ($outcome === 'wrong_amount') {
            $paid = round($paid + 13.37, 2);
        }

        if ($outcome === 'failure') {
            return [
                'status'         => 'success',
                'paymentStatus'  => 'FAILURE',
                'errorCode'      => '5051',
                'errorMessage'   => 'Kart banka tarafından reddedildi (mock).',
                'locale'         => 'tr',
                'systemTime'     => (int) (microtime(true) * 1000),
                'conversationId' => (string) ($req['conversationId'] ?? ''),
                'token'          => $token,
            ];
        }

        $items = [];
        /** @var array<string,mixed> $bi */
        foreach (($req['basketItems'] ?? []) as $i => $bi) {
            $items[] = [
                'itemId'               => (string) ($bi['id'] ?? ('I' . $i)),
                'paymentTransactionId' => 'MOCKTRX-' . substr(md5($token . ':' . ($bi['id'] ?? $i)), 0, 12),
                'price'                => number_format((float) ($bi['price'] ?? 0), 2, '.', ''),
                'paidPrice'            => number_format((float) ($bi['price'] ?? 0), 2, '.', ''),
                'transactionStatus'    => 1,
            ];
        }

        return [
            'status'              => 'success',
            'paymentStatus'       => 'SUCCESS',
            'locale'              => 'tr',
            'systemTime'          => (int) (microtime(true) * 1000),
            'conversationId'      => (string) ($req['conversationId'] ?? ''),
            'price'               => number_format($price, 2, '.', ''),
            'paidPrice'           => number_format($paid, 2, '.', ''),
            'installment'         => 1,
            'currency'            => (string) ($req['currency'] ?? 'TRY'),
            'basketId'            => (string) ($req['basketId'] ?? ''),
            'paymentId'           => 'MOCKPAY-' . substr(str_replace('-', '', $token), 0, 10),
            'fraudStatus'         => $outcome === 'fraud_review' ? 0 : 1,
            'phase'               => 'Authorization',
            'authCode'            => 'MOCKAUTH' . substr(md5($token), 0, 6),
            'token'               => $token,
            'cardType'            => 'CREDIT_CARD',
            'cardAssociation'     => 'MASTER_CARD',
            'binNumber'           => '555555',
            'lastFourDigits'      => '0001',
            'itemTransactions'    => $items,
        ];
    }

    /** İptal simülasyonu. @param array<string,mixed> $body */
    public function cancel(array $body): array
    {
        $paymentId = (string) ($body['paymentId'] ?? '');
        if ($paymentId === '') {
            return ['status' => 'failure', 'errorCode' => '1001', 'errorMessage' => 'paymentId zorunlu (mock).'];
        }
        return [
            'status'               => 'success',
            'locale'               => 'tr',
            'systemTime'           => (int) (microtime(true) * 1000),
            'conversationId'       => (string) ($body['conversationId'] ?? ''),
            'paymentId'            => $paymentId,
            'price'                => 0,
            'currency'             => 'TRY',
            'cancelHostReference'  => 'MOCKCANCEL-' . substr(md5($paymentId . (string) microtime()), 0, 10),
        ];
    }

    /** İade simülasyonu. @param array<string,mixed> $body */
    public function refund(array $body): array
    {
        $trx = (string) ($body['paymentTransactionId'] ?? '');
        if ($trx === '') {
            return ['status' => 'failure', 'errorCode' => '1002', 'errorMessage' => 'paymentTransactionId zorunlu (mock).'];
        }
        return [
            'status'               => 'success',
            'locale'               => 'tr',
            'systemTime'           => (int) (microtime(true) * 1000),
            'conversationId'       => (string) ($body['conversationId'] ?? ''),
            'paymentTransactionId' => $trx,
            'price'                => number_format((float) ($body['price'] ?? 0), 2, '.', ''),
            'currency'             => (string) ($body['currency'] ?? 'TRY'),
            'refundHostReference'  => 'MOCKREFUND-' . substr(md5($trx . (string) microtime()), 0, 10),
        ];
    }

    private function read(string $token): ?array
    {
        $file = $this->dir . '/' . $this->safe($token) . '.json';
        if (!is_readable($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function write(string $token, array $record): void
    {
        $file = $this->dir . '/' . $this->safe($token) . '.json';
        file_put_contents($file, json_encode($record, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** Yol geçişi saldırılarını engeller (yalnız [A-Za-z0-9-]). */
    private function safe(string $token): string
    {
        return preg_replace('/[^A-Za-z0-9-]/', '', $token) ?: 'invalid';
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
