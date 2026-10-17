<?php

declare(strict_types=1);

/**
 * Basit test yardımcıları (PHPUnit bağımlılığı yok).
 * Tam yol: tests/Support/Assert.php
 */

class TestSkip extends RuntimeException
{
}

final class Assert
{
    public static function true(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new RuntimeException($message !== '' ? $message : 'Beklenen: true');
        }
    }

    public static function false(bool $condition, string $message = ''): void
    {
        if ($condition) {
            throw new RuntimeException($message !== '' ? $message : 'Beklenen: false');
        }
    }

    public static function same(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                ($message !== '' ? $message . ' — ' : '') .
                'Beklenen: ' . var_export($expected, true) . ', Gelen: ' . var_export($actual, true)
            );
        }
    }

    public static function count(int $expected, array $actual, string $message = ''): void
    {
        self::same($expected, count($actual), $message);
    }

    public static function contains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(($message !== '' ? $message . ' — ' : '') . "'$needle' içerilmeliydi.");
        }
    }

    /** Kod bloğunun verilen istisnayı fırlatmasını bekler. */
    public static function throws(string $exceptionClass, callable $fn, string $message = ''): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof TestSkip) {
                throw $e;
            }
            if ($e instanceof $exceptionClass || is_a($e, $exceptionClass)) {
                return;
            }
            throw new RuntimeException(
                ($message !== '' ? $message . ' — ' : '') .
                'Beklenen istisna: ' . $exceptionClass . ', Gelen: ' . $e::class . ' (' . $e->getMessage() . ')'
            );
        }
        throw new RuntimeException(($message !== '' ? $message . ' — ' : '') . 'İstisna fırlatılmalıydı: ' . $exceptionClass);
    }
}
