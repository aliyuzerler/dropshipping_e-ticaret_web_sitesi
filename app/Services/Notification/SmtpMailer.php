<?php

declare(strict_types=1);

namespace App\Services\Notification;

/**
 * AŞAMA 5 — Basit SMTP gönderici (fsockopen, AUTH LOGIN, STARTTLS destekli).
 *
 * Canlıya geçişte .env:
 *   MAIL_DRIVER=smtp
 *   SMTP_HOST=smtp.example.com
 *   SMTP_PORT=587
 *   SMTP_USERNAME=...
 *   SMTP_PASSWORD=...
 *   SMTP_SECURE=tls        (tls=STARTTLS | ssl=dört-taraflı TLS | none)
 *   MAIL_FROM=shop@example.com
 *   MAIL_FROM_NAME="Mağaza Adı"
 *
 * Tam yol: app/Services/Notification/SmtpMailer.php
 */
final class SmtpMailer implements MailerInterface
{
    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, array $attachments = []): void
    {
        $host = (string) config('mail.smtp.host', '');
        if ($host === '') {
            throw new \RuntimeException('SMTP_HOST yapılandırılmamış.');
        }
        $port     = (int) config('mail.smtp.port', 587);
        $secure   = (string) config('mail.smtp.secure', 'tls');
        $user     = (string) config('mail.smtp.username', '');
        $pass     = (string) config('mail.smtp.password', '');
        $from     = (string) config('mail.from', 'no-reply@localhost');
        $fromName = (string) config('mail.from_name', 'Mağaza');

        $remote  = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $socket  = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("SMTP bağlantı hatası: $errstr ($errno)");
        }

        try {
            $this->expect($socket, '220');
            $this->command($socket, 'EHLO ' . (parse_url((string) config('app.url', 'localhost'), PHP_URL_HOST) ?: 'localhost'), '250');

            if ($secure === 'tls') {
                $this->command($socket, 'STARTTLS', '220');
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS başarısız.');
                }
                $this->command($socket, 'EHLO localhost', '250');
            }
            if ($user !== '') {
                $this->command($socket, 'AUTH LOGIN', '334');
                $this->command($socket, base64_encode($user), '334');
                $this->command($socket, base64_encode($pass), '235');
            }
            $this->command($socket, 'MAIL FROM:<' . $from . '>', '250');
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', '250');
            $this->command($socket, 'DATA', '354');

            $headers = [
                'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
                'To: ' . self::encodeHeader($toName) . ' <' . $toEmail . '>',
                'Subject: ' . self::encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'Date: ' . date('r'),
            ];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));
            $data = preg_replace('/^\./m', '..', $data) ?? $data; // dot-stuffing
            fwrite($socket, $data . "\r\n.\r\n");
            $this->expect($socket, '250');

            $this->command($socket, 'QUIT', '221');
        } finally {
            fclose($socket);
        }
    }

    private function command($socket, string $cmd, string $expect): void
    {
        fwrite($socket, $cmd . "\r\n");
        $this->expect($socket, $expect);
    }

    private function expect($socket, string $code): void
    {
        $line = '';
        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                throw new \RuntimeException('SMTP yanıtı okunamadı.');
            }
        } while (isset($line[3]) && $line[3] === '-');
        if (!str_starts_with((string) $line, $code)) {
            throw new \RuntimeException('SMTP beklenmeyen yanıt (' . $code . '): ' . trim((string) $line));
        }
    }

    private static function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7e]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
