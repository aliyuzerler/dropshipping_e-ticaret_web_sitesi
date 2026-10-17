<?php

declare(strict_types=1);

/**
 * HealthService testleri (DB olsun/olmasın çalışır).
 * Tam yol: tests/HealthTest.php
 */
use App\Services\Health\HealthService;

return static function (TestRunner $t): void {
    $t->addTest('Health: tüm kontrol anahtarları mevcut', static function (): void {
        $result = (new HealthService())->check();

        foreach (['php_version', 'pdo_mysql', 'xmlreader', 'curl', 'fileinfo', 'mbstring', 'openssl', 'json', 'storage_write', 'database'] as $key) {
            Assert::true(array_key_exists($key, $result['checks']), "checks.$key bulunmalı");
            Assert::true(is_bool($result['checks'][$key]), "checks.$key boolean olmalı");
        }
        Assert::true(in_array($result['status'], ['ok', 'degraded'], true));
    });

    $t->addTest('Health: PHP 8.2+ kontrolü geçer', static function (): void {
        $result = (new HealthService())->check();
        Assert::true($result['checks']['php_version'], 'PHP sürümü >= 8.2 olmalı');
    });

    $t->addTest('Health: yanıt hassas bilgi sızdırmaz', static function (): void {
        $result = (new HealthService())->check();
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        foreach (['password', 'DB_HOST', 'username', 'APP_KEY', '/home/', 'dsn'] as $secret) {
            Assert::false(stripos($json, $secret) !== false, "'$secret' health yanıtında OLMAMALI");
        }
    });
};
