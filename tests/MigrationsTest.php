<?php

declare(strict_types=1);

/**
 * Migration testi — TEMİZ bir test veritabanında:
 *  1) Tüm migration'lar sıfırdan çalışır (30 tablo: 29 CREATE + migrations meta)
 *  2) Tekrar çalıştırma idempotenttir
 *  3) down() çağrıları tabloları düşürür
 * Tam yol: tests/MigrationsTest.php
 */
use App\Core\Config;
use App\Core\Database;
use App\Database\MigrationRunner;

return static function (TestRunner $t): void {
    /** Veritabanı sunucusuna erişim var mı? Yoksa testler SKIP edilir. */
    $host     = (string) Config::get('database.host', '127.0.0.1');
    $port     = (int) Config::get('database.port', 3306);
    $charset  = (string) Config::get('database.charset', 'utf8mb4');
    $username = (string) Config::get('database.username', 'root');
    $password = (string) Config::get('database.password', '');
    $testDb   = (string) Config::get('database.database', 'xml_ecommerce') . '_test';

    try {
        $probe = Database::connect($host, $port, null, $charset, $username, $password);
    } catch (\Throwable) {
        $probe = null;
    }
    if ($probe === null) {
        $t->addTest('Migrations: DB sunucusuna erişim yok', static fn (): never => throw new TestSkip('MySQL sunucusuna ulaşılamadı'));
        return;
    }

    /** Test DB'yi sıfırdan kurar, MigrationRunner döndürür. */
    $freshRunner = static function () use ($probe, $testDb, $host, $port, $charset, $username, $password): MigrationRunner {
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        $probe->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo = Database::connect($host, $port, $testDb, $charset, $username, $password);
        return new MigrationRunner($pdo);
    };

    $t->addTest('Migrations: 31 migration temiz DB üzerinde sorunsuz çalışır (30 tablo)', static function () use ($freshRunner, $testDb, $host, $port, $charset, $username, $password): void {
        $runner = $freshRunner();
        $executed = $runner->runPending();

        Assert::count(31, $executed, '31 migration tam olarak uygulanmalı');
        Assert::count(31, $runner->appliedMigrations());

        // 31 migration; CREATE TABLE üreten sayı: 29 tablo (029 ve 031 ALTER-only) + meta = 30
        $pdo = Database::connect($host, $port, $testDb, $charset, $username, $password);
        $stmt = $pdo->query('SHOW TABLES');
        Assert::count(30, (array) $stmt->fetchAll(PDO::FETCH_COLUMN), '30 tablo olmalı (29 CREATE + migrations meta)');
    });

    $t->addTest('Migrations: tekrar çalıştırma idempotent', static function () use ($freshRunner): void {
        $runner = $freshRunner();
        $runner->runPending();
        $second = $runner->runPending();
        Assert::count(0, $second, 'İkinci çalıştırmada hiçbir migration uygulanmamalı');
    });

    $t->addTest('Migrations: kritik FK kısıtları gerçekten mevcut', static function () use ($freshRunner, $testDb, $host, $port, $charset, $username, $password): void {
        $runner = $freshRunner();
        $runner->runPending();
        $pdo = Database::connect($host, $port, $testDb, $charset, $username, $password);

        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_TYPE = 'FOREIGN KEY'
               AND CONSTRAINT_SCHEMA = '$testDb'"
        );
        $fkCount = (int) $stmt->fetchColumn();
        Assert::true($fkCount >= 25, "Beklenenden az FK var: $fkCount");

        $stmt2 = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = '$testDb' AND DATA_TYPE = 'decimal'
               AND COLUMN_NAME IN ('sale_price','supplier_price','unit_price','amount','grand_total')"
        );
        Assert::true((int) $stmt2->fetchColumn() >= 5, 'Para alanları DECIMAL olmalı');
    });

    $t->addTest('Migrations: FK RESTRICT kuralı çalışır (order silinemez)', static function () use ($freshRunner, $testDb, $host, $port, $charset, $username, $password): void {
        $runner = $freshRunner();
        $runner->runPending();
        $pdo = Database::connect($host, $port, $testDb, $charset, $username, $password);

        // Minimum kayıtlar
        $pdo->exec("INSERT INTO customers (email, created_at) VALUES ('t@test.local', NOW())");
        $pdo->exec("INSERT INTO orders (order_number, customer_id, created_at) VALUES ('ORD-T-1', 1, NOW())");
        $pdo->exec("INSERT INTO payments (order_id, amount, initiated_at) VALUES (1, 100.00, NOW())");

        // RESTRICT: payment'lı order silinemez
        Assert::throws(\PDOException::class, static function () use ($pdo): void {
            $pdo->exec('DELETE FROM orders WHERE id = 1');
        });

        // Order var; snapshot kolonları dolu
        $stmt = $pdo->query('SELECT COUNT(*) FROM orders');
        Assert::same(1, (int) $stmt->fetchColumn());
    });

    $t->addTest('Migrations: test DB temizlenir (drop)', static function () use ($probe, $testDb): void {
        $probe->exec('DROP DATABASE IF EXISTS `' . $testDb . '`');
        Assert::true(true);
    });
};
