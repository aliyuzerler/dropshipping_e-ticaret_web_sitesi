<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

/**
 * Tek satırlık aktarım hatası (satır sayılır, aktarım devam eder).
 *
 * Tam yol: app/Services/Import/RowException.php
 */
final class RowException extends RuntimeException
{
}
