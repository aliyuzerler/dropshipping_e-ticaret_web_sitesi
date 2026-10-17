<?php

declare(strict_types=1);

namespace App\Services\Notification;

/**
 * AŞAMA 5 — Günlük (log) gönderici: VARSAYILAN sürücü.
 *
 * SMTP yapılandırılana kadar e-postalar storage/logs/mail.log'a yapılandırılmış
 * satırlar hâlinde yazılır — böylece bildirim akışı uçtan uca test edilebilir ve
 * canlıya geçişte yalnız .env MAIL_DRIVER değişikliği yeterlidir.
 *
 * Tam yol: app/Services/Notification/LogMailer.php
 */
final class LogMailer implements MailerInterface
{
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments = []): void
    {
        $file = storage_path('logs/mail.log');
        $dir  = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] TO=%s NAME=%s SUBJECT=%s\n%s\n%s\n",
            date('c'),
            $toEmail,
            $toName !== '' ? $toName : '-',
            $subject,
            str_repeat('-', 60),
            $htmlBody
        );
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('mail.log yazılamadı: ' . $file);
        }
    }
}
