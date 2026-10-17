<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Services\Payment\PaymentService;

/**
 * AŞAMA 4 — iyzico webhook alıcı ucu.
 *
 * Resmî dokümantasyon: docs.iyzico.com/ek-servisler/webhook
 *  - Bildirim: sunucudan sunucuya HTTP POST (JSON, HPP formatı: CHECKOUT_FORM_AUTH vb.)
 *  - İmza: X-IYZ-SIGNATURE-V3 başlığı — HMAC-SHA256(secret,
 *    secret + iyziEventType + iyziPaymentId + token + paymentConversationId + status)
 *  - iyzico 2xx alana kadar her 15 dk'da bir, en çok 3 kez tekrarlar:
 *    bu uç DUPLİKE bildirimlere de 2xx döndürür (idempotent işleme).
 *  - İmzasız/bozuk bildirimler 401 ile reddedilir ve webhook_events'e güvenlik
 *    kaydı düşülür; bildirim içeriği ASLA işlenmez (sonuç her zaman sunucu
 *    tarafında token ile iyzico'dan yeniden sorgulanır).
 *
 * CSRF YOK: bu uç dış servis tarafından çağrılır; kimlik doğrulaması imzayla yapılır.
 * Tam yol: app/Controllers/Store/WebhookController.php
 */
final class WebhookController
{
    private PaymentService $payments;

    public function __construct(?PaymentService $payments = null)
    {
        $this->payments = $payments ?? new PaymentService();
    }

    public function handle(Request $request): Response
    {
        $raw = (string) (file_get_contents('php://input') ?: '');
        $signature = (string) ($_SERVER['HTTP_X_IYZ_SIGNATURE_V3'] ?? $_SERVER['HTTP_X_IYZ_SIGNATURE'] ?? '');

        $result = $this->payments->handleWebhook($raw, $signature !== '' ? $signature : null, $request->ip());

        return Response::html($result['body'], $result['http']);
    }
}
