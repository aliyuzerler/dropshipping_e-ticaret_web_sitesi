<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * HTTP durum kodlu istisna.
 *
 * Tam yol: app/Exceptions/HttpException.php
 */
final class HttpException extends \RuntimeException
{
    public function __construct(
        private readonly int $status,
        string $message = ''
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), $status);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public static function notFound(string $path = ''): self
    {
        return new self(404, $path !== '' ? 'Sayfa bulunamadı: ' . $path : 'Sayfa bulunamadı.');
    }

    public static function methodNotAllowed(string $method, string $path): self
    {
        return new self(405, 'Bu istek yöntemi desteklenmiyor.');
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Geçersiz istek.',
            401 => 'Oturum açmalısınız.',
            403 => 'Bu işlem için yetkiniz yok.',
            404 => 'Sayfa bulunamadı.',
            419 => 'Form oturumu süresi doldu, sayfayı yenileyin.',
            422 => 'Doğrulama hatası.',
            429 => 'Çok fazla deneme. Lütfen sonra tekrar deneyin.',
            default => 'Sunucu hatası oluştu.',
        };
    }
}
