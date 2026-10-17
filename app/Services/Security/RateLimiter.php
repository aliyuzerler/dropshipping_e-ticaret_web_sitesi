<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\App;
use RuntimeException;

/**
 * AŞAMA 6 — Basit sabit-pencere hız sınırlayıcı (dosya tabanlı).
 *
 * Amaç: register / şifre sıfırlama talebi / iletişim formu gibi kimlik doğrulamasız
 * uçlara istismar (spam, token üretim seli, log doldurma) karşı best-effort sınır.
 *
 * Tasarım:
 *  - Sayaçlar storage/cache/rate-limit/<sha256(key)>.json altında; dosya başına
 *    {start, count} ve sabit pencere (decaySeconds).
 *  - Yazım FILE_APPEND|LOCK_EX ile atomik değil (oku-değiştir-yaz) — LOCK_EX + tek
 *    satırlık JSON; bu ölçekte yarış payı kabul edilebilir (best-effort sınır).
 *  - Çoklu sunucu / yüksek trafikte Redis tabanlı sayaca geçiş önerilir (kullanım
 *    noktaları değişmeden toTooManyAttempts/hit imzaları korunabilir).
 *  - İstisnalar asla çağıran akışı bozmaz: depolama yazılamazsa "izin var" döner
 *    (fail-open) — hız sınırı erişilebilirlikten öncelikli değildir.
 *
 * Tam yol: app/Services/Security/RateLimiter.php
 */
final class RateLimiter
{
    /**
     * Anahtar bu pencerede sınırı aştı mı?
     */
    public static function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $entry = self::read($key, $decaySeconds);
        if ($entry === null) {
            return false;
        }
        return $entry['count'] >= $maxAttempts;
    }

    /**
     * Sayaç arttırır; pencere ilk vuruşta başlar. Süresi geçmiş kayıt sıfırdan başlar.
     */
    public static function hit(string $key, int $decaySeconds): void
    {
        $entry = self::read($key, $decaySeconds);
        $count = ($entry['count'] ?? 0) + 1;
        $start = $entry !== null && (time() - $entry['start']) < $decaySeconds
            ? $entry['start']
            : time();

        self::write($key, ['start' => $start, 'count' => $count], $decaySeconds);
    }

    /** Anahtarın sayacını temizler (test/yanıt sonrası kullanım). */
    public static function clear(string $key): void
    {
        $file = self::path($key);
        if ($file !== null && is_file($file)) {
            @unlink($file);
        }
    }

    /** @return array{start:int, count:int}|null */
    private static function read(string $key, int $decaySeconds): ?array
    {
        $file = self::path($key);
        if ($file === null || !is_file($file)) {
            return null;
        }
        $raw = (string) @file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['start'], $data['count'])) {
            return null;
        }
        if ((time() - (int) $data['start']) >= $decaySeconds) {
            @unlink($file); // pencere kapandı — kayıt temizlenir
            return null;
        }
        return ['start' => (int) $data['start'], 'count' => (int) $data['count']];
    }

    /** @param array{start:int, count:int} $entry */
    private static function write(string $key, array $entry, int $decaySeconds): void
    {
        $file = self::path($key);
        if ($file === null) {
            return;
        }
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (@file_put_contents($file, json_encode($entry), LOCK_EX) === false) {
            // Depolama yazılamıyor → sınır uygulanamaz; erişilebilirlik korunur.
            return;
        }
    }

    private static function path(string $key): ?string
    {
        try {
            return App::storagePath('cache/rate-limit/' . hash('sha256', $key) . '.json');
        } catch (RuntimeException) {
            return null;
        }
    }
}
