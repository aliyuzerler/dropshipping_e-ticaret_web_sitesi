<?php

declare(strict_types=1);

namespace App\Services\Notification;

/**
 * AŞAMA 5 — E-posta gönderici sözleşmesi.
 *
 * Tam yol: app/Services/Notification/MailerInterface.php
 */
interface MailerInterface
{
    /**
     * E-posta gönderir. İstisna fırlatmak serbesttir — çağıran
     * (NotificationService) hatayı kaydeder ve tekrar deneme kuyruğunda tutar.
     *
     * @param list<string> $attachments yolları (ŞİMDİLİK kullanılmaz; ileriye dönük)
     */
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments = []): void;
}
