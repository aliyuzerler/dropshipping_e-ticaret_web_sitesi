<?php

declare(strict_types=1);

namespace App\Services\Payment;

use RuntimeException;

/**
 * iyzico entegrasyonu hata sınıfı. Mesajlarda ASLA secret key / tam istek gövdesi
 * taşınmaz (log sızıntısı önlenir).
 *
 * Tam yol: app/Services/Payment/IyzipayException.php
 */
final class IyzipayException extends RuntimeException
{
}
