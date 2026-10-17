<?php

declare(strict_types=1);

/**
 * IpGuard (SSRF) + UrlFetcher URL doğrulama testleri.
 * Tam yol: tests/IpGuardTest.php
 */
use App\Services\Http\UrlFetcher;
use App\Services\Security\IpGuard;

return static function (TestRunner $t): void {
    $blockedIpv4 = [
        '127.0.0.1'      => 'loopback',
        '127.8.8.8'      => 'loopback aralığı',
        '10.1.2.3'       => 'private A',
        '172.16.0.1'     => 'private B alt',
        '172.31.255.255' => 'private B üst',
        '192.168.1.1'    => 'private C',
        '169.254.169.254' => 'cloud metadata',
        '0.0.0.0'        => 'this-network',
        '100.64.0.1'     => 'CGNAT',
        '198.18.0.1'     => 'benchmark',
        '224.0.0.1'      => 'multicast',
        '255.255.255.255' => 'broadcast',
        '8.8.8.33.44'    => 'geçersiz format',
        ''               => 'boş',
    ];

    foreach ($blockedIpv4 as $ip => $label) {
        $t->addTest("IpGuard: $ip ($label) ENGELLENİR", static function () use ($ip): void {
            Assert::false(IpGuard::isPublicIp($ip));
        });
    }

    $blockedIpv6 = ['::1', 'fe80::1', 'fd00::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:10.0.0.5', '2001:db8::1'];
    foreach ($blockedIpv6 as $ip) {
        $t->addTest("IpGuard: IPv6 $ip ENGELLENİR", static function () use ($ip): void {
            Assert::false(IpGuard::isPublicIp($ip));
        });
    }

    $public = ['8.8.8.8', '1.1.1.1', '93.184.216.34', '2606:4700:4700::1111', '172.32.0.1', '100.128.0.1'];
    foreach ($public as $ip) {
        $t->addTest("IpGuard: $ip PUBLIC kabul edilir", static function () use ($ip): void {
            Assert::true(IpGuard::isPublicIp($ip));
        });
    }

    $t->addTest('UrlFetcher: file:// şeması reddedilir', static function (): void {
        Assert::throws(RuntimeException::class, static fn () => (new UrlFetcher())->assertSafeUrl('file:///etc/passwd'));
    });

    $t->addTest('UrlFetcher: ftp:// şeması reddedilir', static function (): void {
        Assert::throws(RuntimeException::class, static fn () => (new UrlFetcher())->assertSafeUrl('ftp://example.com/feed.xml'));
    });

    $t->addTest('UrlFetcher: standart dışı port reddedilir (SSRF kanal kapatma)', static function (): void {
        Assert::throws(RuntimeException::class, static fn () => (new UrlFetcher())->assertSafeUrl('http://example.com:8080/feed.xml'));
    });

    $t->addTest('UrlFetcher: URL içinde kullanıcı bilgisi reddedilir', static function (): void {
        Assert::throws(RuntimeException::class, static fn () => (new UrlFetcher())->assertSafeUrl('http://admin:sifre@example.com/feed.xml'));
    });

    $t->addTest('UrlFetcher: localhost (loopback DNS) reddedilir', static function (): void {
        // DNS olmadan /etc/hosts üzerinden 127.0.0.1'e çözülür → fail-closed
        Assert::throws(RuntimeException::class, static fn () => (new UrlFetcher())->assertSafeUrl('http://localhost/feed.xml'));
    });
};
