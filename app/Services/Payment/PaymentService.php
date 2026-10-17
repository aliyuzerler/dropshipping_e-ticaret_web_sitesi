<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\PaymentRepository;
use App\Repositories\WebhookEventRepository;
use App\Services\Order\OrderService;
use App\Services\Order\OrderStatus;
use App\Services\Audit\AuditLogger;
use Throwable;

/**
 * AŞAMA 4 — Ödeme sonucu işleme çekirdeği (idempotent + güvenli).
 *
 * GÜVENLİK İLKELERİ:
 *  1) Tarayıcıdaki başarı mesajı TEK BAŞINA kanıt değildir: sonuç HER ZAMAN
 *     sunucudan iyzico detail API'sine token ile sorgulanır.
 *  2) Callback webhook'unda X-IYZ-SIGNATURE-V3 doğrulanır (resmî HPP şeması:
 *     HMAC-SHA256(secret, secret + eventType + iyziPaymentId + token + conversationId + status)).
 *  3) Tekrarlı callback/webhook idempotent işlenir: webhook_events UNIQUE
 *     (provider, external_id), payments.token UNIQUE, order satırı FOR UPDATE.
 *  4) Tutar/para birimi/conversationId mutlaka siparişle karşılaştırılır;
 *     uyuşmazlıkta ödeme REDDEDİLİR + güvenlik logu yazılır.
 *  5) Kart verisi ASLA saklanmaz (yalnız son 4 hane + kart ailesi bilgi amaçlı).
 *  6) iyzico secret key yalnız sunucu tarafında kalır.
 *
 * Tam yol: app/Services/Payment/PaymentService.php
 */
final class PaymentService
{
    private IyzipayClientInterface $client;

    private PaymentRepository $payments;

    private WebhookEventRepository $webhooks;

    private OrderService $orders;

    public function __construct(
        ?IyzipayClientInterface $client = null,
        ?PaymentRepository $payments = null,
        ?WebhookEventRepository $webhooks = null,
        ?OrderService $orders = null
    ) {
        $this->client   = $client ?? self::defaultClient();
        $this->payments = $payments ?? new PaymentRepository();
        $this->webhooks = $webhooks ?? new WebhookEventRepository();
        $this->orders   = $orders ?? new OrderService();
    }

    /** Yaplandırmaya göre istemci seçer (mock | sandbox | live). */
    public static function defaultClient(): IyzipayClientInterface
    {
        if ((string) config('payment.mode', 'mock') === 'mock') {
            return new IyzipayMockClient();
        }
        return IyzipayClient::fromConfig();
    }

    /** Mock istemci altında senaryo ayarlar (simülasyon/test). */
    public function setMockOutcome(string $token, string $scenario): void
    {
        if (!$this->client->isMock()) {
            throw new IyzipayException('Senaryo ayarı yalnız mock modunda kullanılabilir.');
        }
        /** @var IyzipayMockClient $this->client */
        $this->client->setOutcome($token, $scenario);
    }

    public function isMock(): bool
    {
        return $this->client->isMock();
    }

    /**
     * Token ile ödeme sonucunu SUNUCU TARAFLI sorgular, doğrular ve işler.
     * Callback ve sonuç sayfası kurtarma yolu bu tek metodu kullanır (idempotent).
     *
     * @return array{payment: array<string,mixed>, order: array<string,mixed>, changed: bool, outcome: string}
     *         outcome: success | failed | pending | replay
     */
    public function processToken(string $token, string $ip = '', string $source = 'callback'): array
    {
        $payment = $this->payments->findByToken($token);
        if ($payment === null) {
            Logger::warning('security', 'payment.unknown_token', ['token' => substr($token, 0, 8) . '…', 'ip' => $ip]);
            throw new IyzipayException('Ödeme kaydı bulunamadı.');
        }

        $orderId = (int) $payment['order_id'];

        // Zaten sonuçlanmış ödeme → replay (idempotent)
        if (in_array((string) $payment['status'], ['success', 'failed', 'cancelled'], true)) {
            return [
                'payment' => $payment,
                'order'   => $this->payments->lockOrder($orderId) ?? [],
                'changed' => false,
                'outcome' => 'replay',
            ];
        }

        // Sunucu taraflı sorgu (iyzico detail) — TEK GERÇEK KAYNAK
        /** @var array<string,mixed> $detail */
        $detail = $this->client->request(
            (string) config('payment.endpoints.checkout_retrieve'),
            [
                'locale'         => (string) config('payment.locale', 'tr'),
                'conversationId' => (string) $payment['conversation_id'],
                'token'          => $token,
            ]
        );

        // API düzeyinde hata (token geçersiz vb.) → ödemeyi hatalı kapat
        if (($detail['status'] ?? '') === 'failure') {
            return $this->failPayment($payment, (string) ($detail['errorCode'] ?? 'unknown'), (string) ($detail['errorMessage'] ?? 'iyzico sorgu hatası'), $ip, $source);
        }

        $paymentStatus = strtoupper((string) ($detail['paymentStatus'] ?? ''));
        if ($paymentStatus !== 'SUCCESS') {
            // SUCCESS olmayan (FAILURE / INIT_THREEDS vb.) → FAILURE ise kapat, diğerleri beklemede
            if ($paymentStatus === 'FAILURE') {
                return $this->failPayment($payment, 'payment_failed', (string) ($detail['errorMessage'] ?? 'Ödeme başarısız (iyzico).'), $ip, $source);
            }
            return ['payment' => $payment, 'order' => [], 'changed' => false, 'outcome' => 'pending'];
        }

        // ---- Doğrulama: tutar / para birimi / sipariş ilişkisi ----
        $order = $this->payments->lockOrder($orderId);
        if ($order === null) {
            throw new IyzipayException('Sipariş bulunamadı.');
        }

        $mismatches = [];
        if ((string) ($detail['conversationId'] ?? '') !== (string) $payment['conversation_id']) {
            $mismatches[] = 'conversation_id';
        }
        if (mb_strtolower((string) ($detail['currency'] ?? '')) !== mb_strtolower((string) $payment['currency'])) {
            $mismatches[] = 'currency';
        }
        if (bccomp((string) ($detail['paidPrice'] ?? '0'), (string) $payment['amount'], 2) !== 0) {
            $mismatches[] = 'amount(' . (string) ($detail['paidPrice'] ?? '?') . ' ≠ ' . (string) $payment['amount'] . ')';
        }
        if ($mismatches !== []) {
            Logger::warning('security', 'payment.validation_mismatch', [
                'order_number' => (string) $order['order_number'],
                'source'       => $source,
                'mismatch'     => implode(', ', $mismatches),
            ]);
            return $this->failPayment($payment, 'validation_mismatch', 'Ödeme doğrulaması başarısız: ' . implode(', ', $mismatches), $ip, $source, true);
        }

        // fraudStatus: 1 onay, 0 inceleme, -1 red
        $fraud = (int) ($detail['fraudStatus'] ?? 1);
        if ($fraud !== 1) {
            AuditLogger::log('payment.fraud_review', null, $ip, '', 'order', $orderId, [
                'fraud_status' => $fraud,
                'payment_id'   => (int) $payment['id'],
            ], 'system');
            if ($fraud === -1) {
                return $this->failPayment($payment, 'fraud_rejected', 'Ödeme fraud filtresine takıldı (iyzico).', $ip, $source);
            }
            // 0 → incelemede: henüz 'paid' yapılmaz
            return ['payment' => $payment, 'order' => $order, 'changed' => false, 'outcome' => 'pending'];
        }

        // ---- Transaction: sipariş paid + payment success + tedarikçi siparişleri ----
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Satır kilidiyle yeniden oku (yarış koşullarına karşı)
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$orderId]);
            $locked = $stmt->fetch();
            if ($locked === false) {
                throw new IyzipayException('Sipariş bulunamadı.');
            }

            $payStmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1 FOR UPDATE');
            $payStmt->execute([(int) $payment['id']]);
            $payLocked = $payStmt->fetch();
            if ($payLocked === false) {
                throw new IyzipayException('Ödeme kaydı bulunamadı.');
            }

            // İkili idempotency kontrolü: ödeme zaten success ise replay
            if ((string) $payLocked['status'] === 'success') {
                $pdo->commit();
                return ['payment' => $payLocked, 'order' => $locked, 'changed' => false, 'outcome' => 'replay'];
            }

            $this->orders->markPaidLocked($locked, $payLocked, $detail);

            // Detail yanıtını güvenli alt küme olarak sakla (kart verisi saklanmaz)
            $raw = json_encode([
                'paymentStatus'   => $detail['paymentStatus'] ?? null,
                'price'           => $detail['price'] ?? null,
                'paidPrice'       => $detail['paidPrice'] ?? null,
                'currency'        => $detail['currency'] ?? null,
                'paymentId'       => $detail['paymentId'] ?? null,
                'installment'     => $detail['installment'] ?? null,
                'fraudStatus'     => $detail['fraudStatus'] ?? null,
                'cardType'        => $detail['cardType'] ?? null,
                'cardAssociation' => $detail['cardAssociation'] ?? null,
                'lastFourDigits'  => $detail['lastFourDigits'] ?? null,
                'basketId'        => $detail['basketId'] ?? null,
                'itemTransactions' => array_map(static fn ($it) => [
                    'itemId'               => $it['itemId'] ?? null,
                    'paymentTransactionId' => $it['paymentTransactionId'] ?? null,
                    'price'                => $it['price'] ?? null,
                    'paidPrice'            => $it['paidPrice'] ?? null,
                    'transactionStatus'    => $it['transactionStatus'] ?? null,
                ], (array) ($detail['itemTransactions'] ?? [])),
            ], JSON_UNESCAPED_UNICODE);

            $payFinal = $pdo->prepare(
                'UPDATE payments SET status = "success", raw_response = ?, completed_at = IFNULL(completed_at, NOW()), updated_at = NOW() WHERE id = ?'
            );
            $payFinal->execute([$raw, (int) $payment['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Logger::info('app', 'payment.success', [
            'order_number' => (string) $order['order_number'],
            'payment_id'   => (int) $payment['id'],
            'source'       => $source,
        ]);

        return [
            'payment' => $this->payments->findByToken($token) ?? $payment,
            'order'   => $order,
            'changed' => true,
            'outcome' => 'success',
        ];
    }

    /**
     * iyzico webhook bildirimi (resmî HPP formatı + X-IYZ-SIGNATURE-V3).
     *
     * @return array{http:int, body:string, status:string}
     */
    public function handleWebhook(string $rawBody, ?string $headerSignature, string $ip = ''): array
    {
        /** @var array<string,mixed>|null $payload */
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return ['http' => 400, 'body' => 'invalid json', 'status' => 'invalid'];
        }

        $eventType = (string) ($payload['iyziEventType'] ?? '');
        $token     = (string) ($payload['token'] ?? '');
        $reference = (string) ($payload['iyziReferenceCode'] ?? '');
        $status    = (string) ($payload['status'] ?? '');
        $iyziPayId = (string) ($payload['iyziPaymentId'] ?? '');
        $convId    = (string) ($payload['paymentConversationId'] ?? '');

        // ---- İmza doğrulama (V3, HPP formatı) ----
        $secret = (string) config('payment.secret_key', '');
        $expected = bin2hex(hash_hmac('sha256', $secret . $eventType . $iyziPayId . $token . $convId . $status, $secret, true));
        $valid = $headerSignature !== null
            && $headerSignature !== ''
            && hash_equals($expected, trim($headerSignature));

        $externalId = hash('sha1', $eventType . '|' . $reference . '|' . $token . '|' . $status);

        if (!$valid) {
            // İmzasız/bozuk bildirim ASLA işlenmez; kayıt güvenlik için tutulur.
            try {
                $this->webhooks->recordOnce('iyzico', $eventType ?: 'unknown', 'bad:' . $externalId, $rawBody, false);
            } catch (Throwable) {
                // kayıt hatası akışı etkilemez
            }
            Logger::warning('security', 'webhook.invalid_signature', ['event' => $eventType, 'ip' => $ip]);
            return ['http' => 401, 'body' => 'signature mismatch', 'status' => 'invalid_signature'];
        }

        // ---- Tekrarlı bildirim kontrolü (UNIQUE provider+external_id) ----
        $first = $this->webhooks->recordOnce('iyzico', $eventType, $externalId, $rawBody, true);
        if (!$first) {
            // iyzico 2xx görene kadar tekrar eder; duplicate için de 2xx döneriz (idempotent).
            return ['http' => 200, 'body' => 'duplicate', 'status' => 'duplicate'];
        }

        // ---- Yalnız sonuç bildiren olaylar işlenir ----
        if (!in_array($status, ['SUCCESS', 'FAILURE'], true) || $token === '') {
            $this->webhooks->markProcessed((int) $this->webhookId($externalId), 'ignored');
            return ['http' => 200, 'body' => 'ignored', 'status' => 'ignored'];
        }

        try {
            $result = $this->processToken($token, $ip, 'webhook');
            $this->webhooks->markProcessed((int) $this->webhookId($externalId), 'processed');
            return ['http' => 200, 'body' => $result['outcome'], 'status' => $result['outcome']];
        } catch (IyzipayException $e) {
            $this->webhooks->markProcessed((int) $this->webhookId($externalId), 'failed', $e->getMessage());
            // iyzico tekrar denesin diye 5xx döndürmüyoruz: token bilinmiyorsa
            // tekrarlar da sonuç değişmez; 2xx + hata kaydı yeterli.
            return ['http' => 200, 'body' => 'processing_failed', 'status' => 'failed'];
        }
    }

    private function webhookId(string $externalId): int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM webhook_events WHERE provider = "iyzico" AND external_id = ? LIMIT 1');
        $stmt->execute([$externalId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Ödemeyi hatalı kapatır; siparişi payment_failed'a geçirir (geçiş izinliyse).
     * Tampering şüphesinde (validation_mismatch) güvenlik logu detaylı tutulur.
     */
    private function failPayment(array $payment, string $code, string $message, string $ip, string $source, bool $suspicious = false): array
    {
        $orderId = (int) $payment['order_id'];
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([(int) $payment['id']]);
            $locked = $stmt->fetch();
            if ($locked !== null && (string) $locked['status'] === 'initiated') {
                $upd = $pdo->prepare(
                    'UPDATE payments SET status = "failed", error_code = ?, error_message = ?, completed_at = NOW(), updated_at = NOW() WHERE id = ?'
                );
                $upd->execute([substr($code, 0, 50), mb_substr($message, 0, 250, 'UTF-8'), (int) $payment['id']]);
            }
            $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if ($order !== false && (string) $order['status'] === OrderStatus::PENDING_PAYMENT) {
                $upd = $pdo->prepare('UPDATE orders SET status = "payment_failed", updated_at = NOW() WHERE id = ?');
                $upd->execute([$orderId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditLogger::log($suspicious ? 'payment.tampering_suspected' : 'payment.failed', null, $ip, '', 'order', $orderId, [
            'code'       => $code,
            'message'    => $message,
            'source'     => $source,
            'payment_id' => (int) $payment['id'],
        ], 'system');
        Logger::info('app', 'payment.failed', ['order_id' => $orderId, 'code' => $code, 'source' => $source]);

        return [
            'payment' => $this->payments->findByToken((string) $payment['token']) ?? $payment,
            'order'   => [],
            'changed' => true,
            'outcome' => 'failed',
        ];
    }
}
