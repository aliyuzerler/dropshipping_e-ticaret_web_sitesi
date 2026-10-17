<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO bağlantı yöneticisi (singleton).
 *  - Gerçek prepared statements (ATTR_EMULATE_PREPARES = false)
 *  - Sessiz hata yok: EXCEPTION modu
 *
 * Tam yol: app/Core/Database.php
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host     = (string) Config::get('database.host', '127.0.0.1');
        $port     = (int) Config::get('database.port', 3306);
        $database = (string) Config::get('database.database', '');
        $charset  = (string) Config::get('database.charset', 'utf8mb4');
        $username = (string) Config::get('database.username', '');
        $password = (string) Config::get('database.password', '');

        if ($database === '') {
            throw new RuntimeException('Veritabanı adı yapılandırılmamış (config/database.php veya .env).');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        try {
            self::$pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);

            // Saat dilimi tutarlılığı: NOW()/DATE_SUB gibi SQL zaman fonksiyonları PHP
            // saat dilimiyle (config app.timezone) aynı olmalı — aksi hâlde aktarım
            // zaman damgaları ve feed interval hesapları kayar.
            try {
                $offset = (new \DateTime())->format('P'); // örn. +03:00
                $stmt = self::$pdo->prepare("SET time_zone = ?");
                $stmt->execute([$offset]);
            } catch (\Throwable) {
                // time_zone ayarlanamadıysa sunucu varsayılanı kullanılır (sessiz)
            }
        } catch (PDOException $e) {
            // Bağlantı hatalarında kimlik bilgisi DSN'i sızdırmayalım
            throw new RuntimeException('Veritabanına bağlanılamadı: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    /** Testler / migration runner için ayrı bağlantı (belirtilen DB'ye). */
    public static function connect(string $host, int $port, ?string $database, string $charset, string $username, string $password): PDO
    {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ($database !== null ? ';dbname=' . $database : '') . ';charset=' . $charset;

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }

    public static function disconnect(): void
    {
        if (getenv('DBG_CONN') && self::$pdo !== null) {
            fwrite(STDERR, "[DBG] DISCONNECT! backtrace: " . substr((new \Exception())->getTraceAsString(), 0, 700) . "\n");
        }
        self::$pdo = null;
    }
}
