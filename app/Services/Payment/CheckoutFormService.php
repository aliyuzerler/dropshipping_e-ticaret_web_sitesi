<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Database;
use App\Core\Logger;
use App\Repositories\PaymentRepository;
use App\Services\Order\OrderStatus;
use Throwable;

/**
 * AŞAMA 4 — iyzico Checkout Form başlatma servisi.
 *
 * Siparişi iyzico Checkout Form isteğine çevirir ve payments satırını
 * (token ↔ sipariş ilişkisi UNIQUE) güvenli biçimde yazar.
 *
 * Resmî dokümantasyon: docs.iyzico.com/odeme-metotlari/odeme-formu/cf-entegrasyonu/cf-baslatma
 *  - conversationId = sipariş numarası, basketId = "B-" + sipariş numarası (benzersiz bağ)
 *  - price/paidPrice = siparişin toplamı; basketItems toplamı price'a eşit olmalıdır
 *    (indirim paylaştırılır, yuvarlama farkı son kaleme eklenir).
 *  - Kargo, ayrı bir basket kalemi olarak gönderilir.
 *
 * Güvenlik: secret key ASLA payload'a/frontend'e girmez; callbackUrl üretimi
 * request_base_url() üzerinden yapılır (ters proksi arkasında doğru host).
 *
 * Tam yol: app/Services/Payment/CheckoutFormService.php
 */
final class CheckoutFormService
{
    private IyzipayClientInterface $client;

    private PaymentRepository $payments;

    public function __construct(?IyzipayClientInterface $client = null, ?PaymentRepository $payments = null)
    {
        $this->client   = $client ?? PaymentService::defaultClient();
        $this->payments = $payments ?? new PaymentRepository();
    }

    /**
     * Sipariş için Checkout Form başlatır (idempotent: aktif 'initiated' ödeme
     * varsa yeniden çağrı yapmaz, mevcut token/content'i döndürür).
     *
     * @param array<string,mixed> $order   orders satırı
     * @param list<array<string,mixed>> $items order_items satırları
     * @return array{payment: array<string,mixed>, content: string|null, page_url: string|null}
     * @throws IyzipayException API hatasında (payments satırı initiation_failed ile kayıtlı kalır)
     */
    public function initializeForOrder(array $order, array $items): array
    {
        $orderId   = (int) $order['id'];
        $orderNumber = (string) $order['order_number'];

        // 1) Aktif initiated ödeme varsa yeniden başlatma (idempotent)
        $latest = $this->payments->latestForOrder($orderId);
        if (
            $latest !== null
            && (string) $latest['status'] === 'initiated'
            && (string) ($latest['token'] ?? '') !== ''
        ) {
            return ['payment' => $latest, 'content' => null, 'page_url' => null];
        }

        // 2) İstek gövdesi
        $payload = $this->buildPayload($order, $items);

        // 3) payments satırı (initiated) — token henüz yok, çağrı sonrası yazılır
        $paymentId = $this->payments->insert([
            'order_id'       => $orderId,
            'provider'       => 'iyzico',
            'conversation_id' => $orderNumber,
            'basket_id'      => $payload['basketId'],
            'status'         => 'initiated',
            'amount'         => (float) $order['grand_total'],
            'currency'       => (string) $order['currency'],
            'initiated_at'   => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        try {
            /** @var array<string,mixed> $response */
            $response = $this->client->request(
                (string) config('payment.endpoints.checkout_initialize'),
                $payload
            );
        } catch (IyzipayException $e) {
            $this->payments->update($paymentId, [
                'status'        => 'initiation_failed',
                'error_message' => mb_substr($e->getMessage(), 0, 250, 'UTF-8'),
                'completed_at'  => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            Logger::error('app', 'payment.init_failed', ['order_number' => $orderNumber, 'reason' => $e->getMessage()]);
            throw $e;
        }

        if (($response['status'] ?? '') !== 'success' || empty($response['token'])) {
            $this->payments->update($paymentId, [
                'status'        => 'initiation_failed',
                'error_code'    => substr((string) ($response['errorCode'] ?? 'init'), 0, 50),
                'error_message' => mb_substr((string) ($response['errorMessage'] ?? 'Checkout form başlatılamadı.'), 0, 250, 'UTF-8'),
                'completed_at'  => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            Logger::error('app', 'payment.init_rejected', ['order_number' => $orderNumber]);
            throw new IyzipayException((string) ($response['errorMessage'] ?? 'Checkout form başlatılamadı.'));
        }

        // 4) token ↔ sipariş ilişkisi (UNIQUE token)
        $this->payments->update($paymentId, [
            'token'         => (string) $response['token'],
            'raw_response'  => json_encode([
                'token'          => $response['token'] ?? null,
                'paymentPageUrl' => $response['paymentPageUrl'] ?? null,
            ], JSON_UNESCAPED_UNICODE),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        Logger::info('app', 'payment.checkout_initialized', ['order_number' => $orderNumber]);

        return [
            'payment'  => $this->payments->find($paymentId) ?? [],
            'content'  => (string) ($response['checkoutFormContent'] ?? '') ?: null,
            'page_url' => (string) ($response['paymentPageUrl'] ?? '') ?: null,
        ];
    }

    /**
     * iyzico Checkout Form istek gövdesini üretir (resmî şema).
     * @return array<string,mixed>
     */
    public function buildPayload(array $order, array $items): array
    {
        $pdo = Database::pdo();
        $orderNumber  = (string) $order['order_number'];
        $itemsTotal   = (float) $order['items_total'];
        $discount     = (float) $order['discount_total'];
        $shipping     = (float) $order['shipping_total'];
        $grandTotal   = (float) $order['grand_total'];
        $netItems     = round($itemsTotal - $discount, 2);

        // --- Sepet kalemleri: indirim paylaştırılmış satır tutarları ---
        $basket   = [];
        $assigned = 0.0;
        $count    = count($items);
        foreach ($items as $i => $item) {
            if ($count > 0 && $i === $count - 1) {
                $linePrice = round($netItems - $assigned, 2); // yuvarlama farkı son kaleme
            } else {
                $linePrice = $itemsTotal > 0 ? round(((float) $item['line_total']) * ($netItems / $itemsTotal), 2) : 0.0;
            }
            $assigned = round($assigned + $linePrice, 2);
            $basket[] = [
                'id'       => (string) $item['id'],
                'name'     => mb_substr((string) $item['product_name_snapshot'], 0, 100, 'UTF-8'),
                'category1' => $this->categoryName((int) ($item['product_id'] ?? 0)),
                'itemType' => 'PHYSICAL',
                'price'    => number_format(max(0.0, $linePrice), 2, '.', ''),
            ];
        }
        if ($shipping > 0) {
            $basket[] = [
                'id'        => 'shipping',
                'name'      => 'Kargo Ücreti',
                'category1' => 'Kargo',
                'itemType'  => 'PHYSICAL',
                'price'     => number_format($shipping, 2, '.', ''),
            ];
        }

        // --- Adres snapshot'ları ---
        $shippingAddr = $this->decode($order['shipping_snapshot'] ?? null);
        $billingAddr  = $this->decode($order['billing_snapshot'] ?? null) ?: $shippingAddr;
        $fullName = trim((string) ($billingAddr['full_name'] ?? ''));
        $parts    = preg_split('/\s+/', $fullName) ?: [];
        $firstName = $parts !== [] ? array_shift($parts) : 'Müşteri';
        $lastName  = $parts !== [] ? implode(' ', $parts) : 'Adı';
        $city      = (string) ($billingAddr['city'] ?? '');
        $postal    = (string) ($billingAddr['postal_code'] ?? '');
        $line      = trim(((string) ($billingAddr['line1'] ?? '')) . ' ' . ((string) ($billingAddr['line2'] ?? '')));
        $country   = ((string) ($billingAddr['country_code'] ?? 'TR')) === 'TR' ? 'Turkey' : (string) ($billingAddr['country_code'] ?? 'Turkey');

        $shipFullName = trim((string) ($shippingAddr['full_name'] ?? $fullName));
        $shipCity     = (string) ($shippingAddr['city'] ?? $city);
        $shipPostal   = (string) ($shippingAddr['postal_code'] ?? $postal);
        $shipLine     = trim(((string) ($shippingAddr['line1'] ?? '')) . ' ' . ((string) ($shippingAddr['line2'] ?? ''))) ?: $line;

        // --- Müşteri ---
        $customer = null;
        $stmt = $pdo->prepare('SELECT id, email, phone, created_at FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $order['customer_id']]);
        $customer = $stmt->fetch() ?: null;
        $email    = (string) ($customer['email'] ?? 'musteri@example.local');
        $gsm      = (string) ($shippingAddr['phone'] ?? ($customer['phone'] ?? ''));
        $gsm      = preg_replace('/[^0-9+]/', '', $gsm) ?: '+900000000000';
        if (str_starts_with($gsm, '0')) {
            $gsm = '+90' . substr($gsm, 1);
        }
        $registered = (string) ($customer['created_at'] ?? date('Y-m-d H:i:s'));

        return [
            'locale'              => (string) config('payment.locale', 'tr'),
            'conversationId'      => $orderNumber,
            'price'               => number_format($grandTotal, 2, '.', ''),
            'paidPrice'           => number_format($grandTotal, 2, '.', ''),
            'currency'            => (string) config('payment.currency', 'TRY'),
            'basketId'            => 'B-' . $orderNumber,
            'paymentGroup'        => 'PRODUCT',
            'callbackUrl'         => request_base_url() . '/odeme/callback',
            'enabledInstallments' => (array) config('payment.enabled_installments', [1]),
            'buyer'               => [
                'id'                  => (string) $order['customer_id'],
                'name'                => $firstName,
                'surname'             => $lastName,
                'identityNumber'      => (string) config('payment.identity_fallback', '11111111111'),
                'email'               => $email,
                'ip'                  => (string) ($order['ip_address'] ?? '127.0.0.1'),
                'gsmNumber'           => $gsm,
                'registrationDate'    => $registered,
                'lastLoginDate'       => date('Y-m-d H:i:s'),
                'registrationAddress' => mb_substr($line !== '' ? $line : 'Adres bilgisi yok', 0, 250, 'UTF-8'),
                'city'                => mb_substr($city !== '' ? $city : 'İstanbul', 0, 80, 'UTF-8'),
                'country'             => $country,
                'zipCode'             => mb_substr($postal, 0, 16, 'UTF-8'),
            ],
            'shippingAddress' => [
                'address'     => mb_substr($shipLine !== '' ? $shipLine : $line, 0, 250, 'UTF-8'),
                'zipCode'     => mb_substr($shipPostal, 0, 16, 'UTF-8'),
                'contactName' => mb_substr($shipFullName !== '' ? $shipFullName : $fullName, 0, 120, 'UTF-8'),
                'city'        => mb_substr($shipCity !== '' ? $shipCity : $city, 0, 80, 'UTF-8'),
                'country'     => $country,
            ],
            'billingAddress' => [
                'address'     => mb_substr($line !== '' ? $line : $shipLine, 0, 250, 'UTF-8'),
                'zipCode'     => mb_substr($postal, 0, 16, 'UTF-8'),
                'contactName' => mb_substr($fullName !== '' ? $fullName : $shipFullName, 0, 120, 'UTF-8'),
                'city'        => mb_substr($city !== '' ? $city : $shipCity, 0, 80, 'UTF-8'),
                'country'     => $country,
            ],
            'basketItems' => $basket,
        ];
    }

    /** Ürünün kategori adı (basket category1 için). */
    private function categoryName(int $productId): string
    {
        if ($productId <= 0) {
            return 'Genel';
        }
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT c.name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? LIMIT 1'
            );
            $stmt->execute([$productId]);
            $name = $stmt->fetchColumn();
            return $name !== false && (string) $name !== '' ? (string) $name : 'Genel';
        } catch (Throwable) {
            return 'Genel';
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
