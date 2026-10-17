<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Core\Logger;
use App\Repositories\CustomerRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\OrderRepository;

/**
 * AŞAMA 5 — E-posta bildirim servisi.
 *
 * Özellikler (görev gereklilikleri):
 *  - Olay temelli kuyruk: notifications tablosu + dedup_key UNIQUE →
 *    "Sipariş alındı, Ödeme onaylandı, Tedarikçiye iletildi, Kargoya verildi,
 *     Teslim edildi, İptal, İade durumu" olaylarının TEKRARLI GÖNDERİMİ İMKÂNSIZ.
 *    (callback + webhook aynı olayı tetiklese bile tek satır oluşur.)
 *  - Gönderim hataları kaydedilir (attempts++, last_error) ve cron kuyruğunda
 *    tekrar denenir; max deneme aşılırsa kuyrukta kalır ama denemez (manuel bakış).
 *  - Gönderici sürücüsü .env ile seçilir: log (varsayılan) | smtp.
 *  - Asla ana akışı bozmaz: tüm metodlar try/catch içinde çalışır.
 *
 * Tam yol: app/Services/Notification/NotificationService.php
 */
final class NotificationService
{
    public const EVENT_ORDER_RECEIVED   = 'order.received';
    public const EVENT_PAYMENT_CONFIRMED = 'payment.confirmed';
    public const EVENT_SENT_TO_SUPPLIER = 'order.sent_to_supplier';
    public const EVENT_SHIPPED          = 'order.shipped';
    public const EVENT_DELIVERED        = 'order.delivered';
    public const EVENT_CANCELLED        = 'order.cancelled';
    public const EVENT_REFUND_CHANGED   = 'refund.status_changed';

    private const MAX_ATTEMPTS = 5;

    /* ==================== Olay üreticileri (statik, güvenli) ==================== */

    /** Sipariş alındı (checkout sonrası, ödeme öncesi). */
    public static function orderReceived(array $order): void
    {
        $number = (string) $order['order_number'];
        $total  = number_format((float) $order['grand_total'], 2, ',', '.') . ' ₺';
        self::push(
            self::EVENT_ORDER_RECEIVED,
            $order,
            'Siparişiniz alındı — ' . $number,
            '<p>Merhaba,</p><p><strong>' . $number . '</strong> numaralı siparişiniz sisteme kaydedildi.'
            . ' Toplam: <strong>' . $total . '</strong>.</p>'
            . '<p>Siparişinizi tamamlamak için ödeme adımına geçebilirsiniz.</p>'
        );
    }

    /** Ödeme onaylandı (iyzico sunucu-taraflı doğrulama sonrası). */
    public static function paymentConfirmed(array $order): void
    {
        $number = (string) $order['order_number'];
        $total  = number_format((float) $order['grand_total'], 2, ',', '.') . ' ₺';
        self::push(
            self::EVENT_PAYMENT_CONFIRMED,
            $order,
            'Ödemeniz onaylandı — ' . $number,
            '<p>Merhaba,</p><p><strong>' . $number . '</strong> numaralı siparişinizin ödemesi onaylandı.'
            . ' Tutar: <strong>' . $total . '</strong>.</p>'
            . '<p>Siparişiniz hazırlanmak üzere tedarikçimize iletiliyor.</p>'
        );
    }

    /** Sipariş tedarikçiye iletildi. */
    public static function supplierOrderSent(array $supplierOrder, ?string $externalReference): void
    {
        $number = (string) ($supplierOrder['order_number'] ?? '');
        $ref    = $externalReference !== null && $externalReference !== ''
            ? ' Tedarikçi referansı: <code>' . htmlspecialchars($externalReference, ENT_QUOTES, 'UTF-8') . '</code>.'
            : '';
        self::push(
            self::EVENT_SENT_TO_SUPPLIER,
            ['id' => (int) ($supplierOrder['order_id'] ?? 0), 'order_number' => $number],
            'Siparişiniz tedarikçiye iletildi — ' . $number,
            '<p>Merhaba,</p><p><strong>' . $number . '</strong> numaralı siparişiniz hazırlanmak üzere tedarikçiye iletildi.' . $ref . '</p>'
        );
    }

    /** Kargoya verildi (tüm gönderiler veya ilk gönderi). */
    public static function orderShipped(array $order, array $shipments): void
    {
        $number = (string) $order['order_number'];
        $rows = '';
        foreach ($shipments as $shipment) {
            if (empty($shipment['tracking_number'])) {
                continue;
            }
            $rows .= '<li>' . htmlspecialchars((string) ($shipment['supplier_name'] ?? 'Tedarikçi'), ENT_QUOTES, 'UTF-8')
                . ' — ' . htmlspecialchars((string) ($shipment['carrier'] ?? ''), ENT_QUOTES, 'UTF-8')
                . ': <strong>' . htmlspecialchars((string) $shipment['tracking_number'], ENT_QUOTES, 'UTF-8') . '</strong></li>';
        }
        $tracking = $rows !== '' ? '<ul>' . $rows . '</ul>' : '';
        self::push(
            self::EVENT_SHIPPED,
            $order,
            'Siparişiniz kargoya verildi — ' . $number,
            '<p>Merhaba,</p><p><strong>' . $number . '</strong> numaralı siparişiniz kargoya verildi.</p>' . $tracking
            . '<p>Hesabınızdan takip edebilirsiniz.</p>'
        );
    }

    /** Sipariş teslim edildi. */
    public static function orderDelivered(array $order): void
    {
        $number = (string) $order['order_number'];
        self::push(
            self::EVENT_DELIVERED,
            $order,
            'Siparişiniz teslim edildi — ' . $number,
            '<p>Merhaba,</p><p><strong>' . $number . '</strong> numaralı siparişiniz teslim edildi. Bizi tercih ettiğiniz için teşekkürler!</p>'
        );
    }

    /** Sipariş iptal edildi. */
    public static function orderCancelled(array $order, string $reason = ''): void
    {
        $number = (string) $order['order_number'];
        $reason = $reason !== '' ? '<p>İptal nedeni: ' . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        self::push(
            self::EVENT_CANCELLED,
            $order,
            'Siparişiniz iptal edildi — ' . $number,
            '<p>Merhaba,</p><p><strong>' . $number . '</strong> numaralı siparişiniz iptal edildi.</p>' . $reason
            . '<p>Ödemeniz yapılmışsa iade süreci başlatılacaktır.</p>'
        );
    }

    /** İade durumu değişti (talep/onay/tamam/red). */
    public static function refundStatusChanged(array $order, string $stage, string $detail = ''): void
    {
        $number = (string) $order['order_number'];
        $stages = [
            'requested' => 'İade talebiniz alındı',
            'completed' => 'İadeniz tamamlandı',
            'rejected'  => 'İade talebiniz reddedildi',
            'failed'    => 'İade işlemi başarısız oldu',
        ];
        $title = $stages[$stage] ?? 'İade durumu güncellendi';
        self::push(
            self::EVENT_REFUND_CHANGED . '.' . $stage,
            $order,
            $title . ' — ' . $number,
            '<p>Merhaba,</p><p><strong>' . $number . '</strong> numaralı siparişinizde iade durumu güncellendi: <strong>' . $title . '</strong>.</p>'
            . ($detail !== '' ? '<p>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>' : '')
        );
    }

    /* ==================== Kuyruk ==================== */

    /**
     * Olayı kuyruğa yazar (dedup anahtarıyla — tekrarsız) ve hemen gönderim dener.
     * Hata ana akışı bozmaz; kuyrukta kalır, cron tekrar dener.
     */
    private static function push(string $event, array $order, string $subject, string $htmlBody): void
    {
        try {
            $orderId = (int) ($order['id'] ?? 0);
            $email   = self::customerEmail($orderId);
            if ($email === '' || $orderId <= 0) {
                return; // alıcı yoksa bildirim üretilmez
            }
            $repo    = self::repo();
            $dedupKey = $event . ':order:' . $orderId . ':' . strtolower($email);
            $inserted = $repo->enqueueOnce($event, $email, $subject, $htmlBody, 'order', $orderId, $dedupKey);
            if (!$inserted) {
                return; // zaten kuyrukta/gönderildi — tekrarsız
            }
            $row = $repo->findByDedupKey($dedupKey);
            if ($row === null) {
                return;
            }
            self::attemptSend($repo, 'n' . $row['id'], $row);
        } catch (\Throwable $e) {
            Logger::error('app', 'notification.enqueue_failed', ['event' => $event, 'reason' => $e->getMessage()]);
        }
    }

    /**
     * Kuyruğu boşaltır (cron/CLI: notifications:send). Başarısızlar attempts++
     * ile kaydedilir ve bir sonraki turda tekrar denenir.
     *
     * @return array{sent:int, failed:int, remaining:int}
     */
    public static function processQueue(int $limit = 100): array
    {
        $repo = self::repo();
        $sent = $failed = 0;
        foreach ($repo->pending($limit, self::MAX_ATTEMPTS) as $notification) {
            try {
                self::attemptSend($repo, 'n' . $notification['id'], $notification);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                Logger::error('mail', 'notification.send_failed', [
                    'notification_id' => (int) $notification['id'],
                    'event'           => (string) $notification['event'],
                    'attempts'        => (int) $notification['attempts'],
                    'reason'          => $e->getMessage(),
                ]);
            }
        }
        return ['sent' => $sent, 'failed' => $failed, 'remaining' => count($repo->pending($limit, self::MAX_ATTEMPTS))];
    }

    /** @param array<string,mixed> $notification */
    private static function attemptSend(NotificationRepository $repo, string $logKey, array $notification): void
    {
        $repo->markSending((int) $notification['id']);
        try {
            self::mailer()->send(
                (string) $notification['recipient_email'],
                '',
                (string) $notification['subject'],
                (string) $notification['body']
            );
            $repo->markSent((int) $notification['id']);
            Logger::info('mail', 'notification.sent', [
                'id' => (int) $notification['id'], 'event' => (string) $notification['event'], 'key' => $logKey,
            ]);
        } catch (\Throwable $e) {
            $repo->markFailed((int) $notification['id'], $e->getMessage(), self::MAX_ATTEMPTS);
            throw $e;
        }
    }

    private static function mailer(): MailerInterface
    {
        $driver = (string) config('mail.driver', 'log');
        if ($driver === 'smtp') {
            return new SmtpMailer();
        }
        return new LogMailer();
    }

    private static function repo(): NotificationRepository
    {
        // BİLİNÇLİ olarak instance CACHE'LENMEZ: test suite'lerinde (ve uzun yaşayan
        // işçilerde) Database::disconnect() sonrası eski PDO başka bir veritabanına
        // yazabilirdi. Her çağrı güncel bağlantıyla taze repository üretir.
        return new NotificationRepository();
    }

    private static function customerEmail(int $orderId): string
    {
        if ($orderId <= 0) {
            return '';
        }
        $order = (new OrderRepository())->find($orderId);
        if ($order === null || (int) $order['customer_id'] <= 0) {
            return '';
        }
        $customer = (new CustomerRepository())->find((int) $order['customer_id']);
        return $customer !== null ? (string) $customer['email'] : '';
    }
}
