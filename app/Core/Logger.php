<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON-satır formatlı dosya logger'ı (app / security kanalları).
 * 10 MB üzerinde basit rotasyon: app.log -> app.log.1
 *
 * Tam yol: app/Core/Logger.php
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    /** AŞAMA 6: loglarda asla görünmemesi gereken anahtar adları (içerir kuralı, case-insensitive). */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'passwd', 'pwd',
        'card', 'cvv', 'cvc',
        'secret', 'token', 'authorization', 'auth_key', 'apikey', 'api_key',
        'cookie', 'session_id', 'credentials',
    ];

    private const MASK = '***';

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write($channel, 'info', $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write($channel, 'warning', $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write($channel, 'error', $message, $context);
    }

    public static function debug(string $channel, string $message, array $context = []): void
    {
        self::write($channel, 'debug', $message, $context);
    }

    /** Güvenlik olayları ayrı kanala: storage/logs/security.log */
    public static function security(string $event, array $context = []): void
    {
        self::write('security', 'warning', $event, $context);
    }

    private static function write(string $channel, string $level, string $message, array $context): void
    {
        $min = self::LEVELS[(string) Config::get('app.log_level', 'info')] ?? 1;
        if ((self::LEVELS[$level] ?? 1) < $min) {
            return;
        }

        $dir = App::storagePath('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $file = $dir . '/' . $channel . '.log';

        $record = [
            'ts'      => date('c'),
            'level'   => $level,
            'channel' => $channel,
            'message' => $message,
        ];
        if ($context !== []) {
            // AŞAMA 6: hassas anahtarlar log dosyasına yazılmadan maskelenir.
            $record['context'] = self::redact($context);
        }

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }

        // Basit rotasyon
        if (is_file($file) && filesize($file) > 10 * 1024 * 1024) {
            @rename($file, $file . '.' . date('YmdHis'));
        }

        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * AŞAMA 6 — Context redaksiyonu: hassas anahtarlı değerleri maskeler,
     * iç içe dizileri özyinelemeli tarar (anahtar adı "içerir" kuralı, case-insensitive;
     * örn. card_number, smtp_password, auth_token gibi varyantları da yakalar).
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $out[$key] = self::isSensitiveKey((string) $key)
                ? self::MASK
                : (is_array($value) ? self::redact($value) : $value);
        }
        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }
        return false;
    }
}
