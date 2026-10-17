<?php

declare(strict_types=1);

namespace App\Services\Http;

use App\Core\Logger;
use App\Services\Security\IpGuard;
use RuntimeException;

/**
 * SSRF-güvenli uzak dosya indirici (XML feed URL'leri için).
 *
 * Savunma katmanları:
 *  - Yalnız http/https + port 80/443
 *  - DNS çözümlenir; TÜM kayıtlar public değilse reddedilir (fail-closed)
 *  - CURLOPT_RESOLVE ile doğrulanan IP sabitlenir (DNS rebinding koruması)
 *  - FOLLOWLOCATION kapalı; her redirect hedefi yeniden doğrulanır (max N hop)
 *  - Boyut limiti, zaman aşımı, TLS doğrulaması asla kapatılmaz
 *
 * Tam yol: app/Services/Http/UrlFetcher.php
 */
final class UrlFetcher
{
    /**
     * @return array{body:string, final_url:string, status:int, content_type:string}
     *
     * @throws RuntimeException güvenlik ihlali veya ağ hatası
     */
    public function fetch(string $url, int $maxBytes, ?string $basicAuth = null): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Uzak URL çekme için cURL eklentisi gerekli (fallback güvenli değil).');
        }

        $maxRedirects = (int) config('xml.fetch.max_redirects', 3);
        $timeout = (int) config('xml.fetch.timeout_seconds', 30);
        $userAgent = (string) config('xml.fetch.user_agent', 'XmlFeedBot/1.0');

        $currentUrl = $url;
        $visited = [];

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $this->assertSafeUrl($currentUrl); // şema/port/host → IP doğrulama

            $parts = parse_url($currentUrl);
            if ($parts === false || !isset($parts['host'])) {
                throw new RuntimeException('Geçersiz URL: ' . $currentUrl);
            }
            $host = (string) $parts['host'];
            $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80));

            // DNS çözümle + tüm IP'leri doğrula
            $ip = $this->resolvePublicIp($host);

            $ch = curl_init();
            if ($ch === false) {
                throw new RuntimeException('cURL başlatılamadı.');
            }

            $body = '';
            $sizeExceeded = false;

            curl_setopt_array($ch, [
                CURLOPT_URL            => $currentUrl,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,          // redirect'ler elle, her adım doğrulanır
                CURLOPT_RESOLVE        => ["$host:$port:$ip"], // rebinding'e karşı IP sabitleme
                CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_USERAGENT      => $userAgent,
                CURLOPT_MAXFILESIZE    => $maxBytes,
                CURLOPT_SSL_VERIFYPEER => true,            // asla kapatma
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADER         => false,
                CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body, &$sizeExceeded, $maxBytes): int {
                    $body .= $chunk;
                    if (strlen($body) > $maxBytes) {
                        $sizeExceeded = true;
                        return -1; // transferi kes
                    }
                    return strlen($chunk);
                },
            ]);

            if ($basicAuth !== null && $basicAuth !== '') {
                curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
            }

            // Yalnız başlıkları da alabilmek için ayrı bir istek yerine header'ı body öncesi yakalamak yerine:
            // önce HEAD yerine GET ile başlık+gövde birlikte alınıyor; durum kodu info'dan okunur.
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$statusLine): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', trim($line), $m) === 1) {
                    $statusLine = (int) $m[1];
                }
                return strlen($line);
            });
            $statusLine = 0;

            $result = curl_exec($ch);
            $contentType = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
            $curlError = curl_error($ch);
            $status = $statusLine !== 0 ? $statusLine : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($sizeExceeded) {
                throw new RuntimeException('Dosya boyutu sınırı aşıldı (' . $maxBytes . ' bayt).');
            }
            if ($result === false) {
                throw new RuntimeException('İndirme hatası: ' . $curlError);
            }

            if ($status >= 300 && $status < 400) {
                $location = $this->extractLocationHeader($currentUrl, $body, $contentType);
                if ($location === null) {
                    throw new RuntimeException('Yönlendirme başlığı okunamadı.');
                }
                $currentUrl = $this->absolutize($location, $currentUrl);
                $visited[] = $currentUrl;
                if (count($visited) > $maxRedirects) {
                    throw new RuntimeException('Çok fazla yönlendirme (max ' . $maxRedirects . ').');
                }
                $body = '';
                continue;
            }

            if ($status < 200 || $status >= 300) {
                Logger::security('urlfetch.non_2xx', ['url_host' => $host, 'status' => $status]);
                throw new RuntimeException('Uzak sunucu hata döndürdü (HTTP ' . $status . ').');
            }

            return [
                'body'         => $body,
                'final_url'    => $currentUrl,
                'status'       => $status,
                'content_type' => $contentType,
            ];
        }

        throw new RuntimeException('Yönlendirme limiti aşıldı.');
    }

    /** URL'i şema/port/host bazında doğrular; host için tüm DNS kayıtları public olmalı. */
    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new RuntimeException('URL ayrıştırılamadı.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $allowedSchemes = (array) config('xml.fetch.allowed_schemes', ['http', 'https']);
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new RuntimeException('Yalnız http/https desteklenir.');
        }

        $allowedPorts = (array) config('xml.fetch.allowed_ports', [80, 443]);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, $allowedPorts, true)) {
            throw new RuntimeException('Yalnız 80/443 portlarına izin verilir.');
        }

        // Kullanıcı bilgisi şüphelidir (http://user@internal/)
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('URL içinde kullanıcı bilgisi kabul edilmez.');
        }

        $this->resolvePublicIp((string) $parts['host']);
    }

    private function resolvePublicIp(string $host): string
    {
        // Doğrudan IP verilmişse
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            IpGuard::assertPublicIp($host);
            return $host;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            // dns_get_record bazı ortamlarda başarısız olabilir; gethostbyname ile A kaydı dene
            $a = @gethostbyname($host);
            if ($a !== $host && filter_var($a, FILTER_VALIDATE_IP)) {
                $records = [['type' => 'A', 'ip' => $a]];
            } else {
                throw new RuntimeException('DNS çözümlemesi başarısız: ' . $host);
            }
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }
        if ($ips === []) {
            throw new RuntimeException('Host için IP bulunamadı: ' . $host);
        }

        // fail-closed: tek bir özel kayıt bile varsa reddet
        foreach ($ips as $ip) {
            IpGuard::assertPublicIp($ip);
        }

        return $ips[0];
    }

    private function extractLocationHeader(string $url, string $body, string $contentType): ?string
    {
        // cURL body'yi döndürdü; Location başlığını yakalayabilmek için HEADERFUNCTION
        // yalnız durum satırını yakaladı. Ayrı küçük bir header isteği yapılır.
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        $headers = '';
        curl_setopt_array($ch, [
            CURLOPT_NOBODY          => true,
            CURLOPT_HEADER          => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => 10,
            CURLOPT_HEADERFUNCTION  => function ($ch, string $line) use (&$headers): int {
                $headers .= $line;
                return strlen($line);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);

        if (preg_match('/^Location:\s*(\S+)/mi', $headers, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private function absolutize(string $location, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Göreli yönlendirme çözümlenemedi.');
        }
        $base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $base . $location;
        }
        $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
        return $base . ($path ?? '/') . $location;
    }
}
