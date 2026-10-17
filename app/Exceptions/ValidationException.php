<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Doğrulama hatası (422).
 *
 * Tam yol: app/Exceptions/ValidationException.php
 */
final class ValidationException extends \RuntimeException
{
    /**
     * @param array<string,list<string>> $errors alan => hata mesajları
     */
    public function __construct(private readonly array $errors, string $message = 'Doğrulama hatası oluştu.')
    {
        parent::__construct($message, 422);
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return (string) $messages[0];
            }
        }
        return 'Doğrulama hatası oluştu.';
    }
}
