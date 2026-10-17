<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Config;
use RuntimeException;

/**
 * APP_KEY tabanlı AES-256-GCM şifreleme (feed şifreleri vb. için).
 * Format: base64(iv . cipherText . tag)
 *
 * Tam yol: app/Services/Security/Cipher.php
 */
final class Cipher
{
    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipherText === false) {
            throw new RuntimeException('Şifreleme başarısız.');
        }
        return base64_encode($iv . $cipherText . $tag);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) { // 12 iv + 16 tag minimum
            throw new RuntimeException('Şifreli veri geçersiz.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $cipherText = substr($raw, 12, -16);
        $plain = openssl_decrypt($cipherText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Şifre çözme başarısız (veri bozulmuş olabilir).');
        }
        return $plain;
    }

    private static function key(): string
    {
        $encoded = (string) Config::get('security.app_key', '');
        if ($encoded === '') {
            throw new RuntimeException('APP_KEY tanımlı değil (.env). Feed şifreleri saklanamaz.');
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException('APP_KEY geçersiz (en az 32 byte, base64).');
        }
        return substr($key, 0, 32);
    }
}
