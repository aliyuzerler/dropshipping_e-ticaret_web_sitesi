<?php

declare(strict_types=1);

namespace App\Services\Security;

use RuntimeException;

/**
 * IP güvenlik muhafızı (SSRF savunması çekirdeği).
 *  - Özel/rezerve tüm IPv4/IPv6 aralıklarını reddeder (fail-closed)
 *  - IPv4-mapped IPv6 adreslerini IPv4 kuralına tabi tutar
 *
 * Tam yol: app/Services/Security/IpGuard.php
 */
final class IpGuard
{
    /** @throws RuntimeException IP özel/rezerve/geçersiz ise */
    public static function assertPublicIp(string $ip): void
    {
        if (!self::isPublicIp($ip)) {
            throw new RuntimeException('Kısıtlı/özel IP adresine erişim engellendi: ' . $ip);
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // IPv4-mapped IPv6 (::ffff:10.0.0.1) → IPv4'e çevir
        if (str_contains($ip, ':') && str_contains($ip, '.')) {
            $mapped = strtolower($ip);
            if (str_starts_with($mapped, '::ffff:')) {
                $ip = substr($mapped, 7);
            }
        }

        if (str_contains($ip, ':')) {
            return self::isPublicIpv6(strtolower($ip));
        }
        return self::isPublicIpv4($ip);
    }

    private static function isPublicIpv4(string $ip): bool
    {
        $parts = array_map('intval', explode('.', $ip));
        if (count($parts) !== 4 || min($parts) < 0 || max($parts) > 255) {
            return false;
        }
        [$a, $b] = $parts;

        // Genel `filter_var` kontrolü (private/reserved flag'leri)
        $okByFilter = (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$okByFilter) {
            return false;
        }

        // Ek savunma katmanı (PHP flag'lerinin kapsamadığı/belirsiz kaldığı aralıklar)
        if ($a === 0) return false;                       // 0.0.0.0/8 "this network"
        if ($a === 10) return false;                      // 10/8
        if ($a === 127) return false;                     // loopback
        if ($a === 169 && $b === 254) return false;       // link-local + metadata (169.254.169.254)
        if ($a === 172 && $b >= 16 && $b <= 31) return false;
        if ($a === 192 && $b === 168) return false;
        if ($a === 100 && $b >= 64 && $b <= 127) return false;  // CGNAT
        if ($a === 198 && ($b === 18 || $b === 19)) return false; // benchmark
        if ($a >= 224) return false;                      // multicast + reserved + broadcast

        return true;
    }

    private static function isPublicIpv6(string $ip): bool
    {
        $blockedPrefixes = [
            '::',            // unspecified (:: ve ::1)
            '::1',
            'fe80',          // link-local
            'fc', 'fd',      // unique local (fc00::/7)
            'ff',            // multicast
            '::ffff:',       // IPv4-mapped (kalanlar)
            '64:ff9b:',      // NAT64 bilinen önek
            '2002',          // 6to4 (içine IPv4 gömülü)
            '2001:db8',      // dokümantasyon
        ];
        foreach ($blockedPrefixes as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return false;
            }
        }
        // teredo 2001::/32 (içine IPv4 gömülü) — konservatif blok
        if (str_starts_with($ip, '2001:0')) {
            return false;
        }
        return true;
    }
}
