<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

/**
 * MigrationRunner: app/Database/Migrations/*.php dosyalarını sırayla,
 * idempotent biçimde çalıştırır. Durum `migrations` meta tablosunda tutulur.
 *
 * Tam yol: app/Database/MigrationRunner.php
 */
final class MigrationRunner
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureMetaTable();
    }

    /**
     * Bekleyen tüm migration'ları çalıştırır.
     *
     * @return list<string> uygulanan migration adları
     */
    public function runPending(callable $onProgress = null): array
    {
        $applied = $this->appliedMigrations();
        $batch = $this->nextBatch();
        $executed = [];

        foreach ($this->loadMigrations() as $migration) {
            $name = $migration->name();
            if (in_array($name, $applied, true)) {
                continue;
            }

            // NOT: MySQL'de DDL (CREATE/ALTER) implicit commit yapar; transaction
            // sarması anlamsızdır ve "no active transaction" hatasına yol açar.
            // Bu yüzden: DDL çalıştırılır → başarırsa kayıt satırı yazılır.
            try {
                foreach ($migration->up() as $sql) {
                    $this->pdo->exec($sql);
                }
                $stmt = $this->pdo->prepare('INSERT INTO migrations (migration, batch, applied_at) VALUES (?, ?, NOW())');
                $stmt->execute([$name, $batch]);
            } catch (\Throwable $e) {
                throw new RuntimeException(sprintf('Migration başarısız [%s]: %s', $name, $e->getMessage()), 0, $e);
            }

            $executed[] = $name;
            if ($onProgress !== null) {
                $onProgress($name);
            }
        }

        return $executed;
    }

    /**
     * @return list<string> uygulanmış migration adları
     */
    public function appliedMigrations(): array
    {
        $rows = $this->pdo->query('SELECT migration FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        /** @var list<string> $rows */
        return $rows;
    }

    /**
     * Migrations dizinindeki tüm migration nesneleri (numara sırasına göre).
     *
     * @return list<MigrationInterface>
     */
    public function loadMigrations(): array
    {
        $dir = dirname(__DIR__, 2) . '/database/migrations';
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        $migrations = [];
        foreach ($files as $file) {
            $basename = basename($file, '.php');
            require_once $file;
            $studly = $this->studly($basename);
            // Hem "CreateSuppliers" hem "CreateSuppliersTable" adlandırmasını destekle
            $class = 'App\\Database\\Migrations\\' . $studly;
            if (!class_exists($class)) {
                $class = 'App\\Database\\Migrations\\' . $studly . 'Table';
            }
            if (!class_exists($class)) {
                throw new RuntimeException('Migration sınıfı bulunamadı: ' . $class);
            }
            $object = new $class();
            if (!$object instanceof MigrationInterface) {
                throw new RuntimeException('MigrationInterface uygulanmıyor: ' . $class);
            }
            $migrations[] = $object;
        }
        return $migrations;
    }

    private function ensureMetaTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration VARCHAR(190) NOT NULL,
                batch INT UNSIGNED NOT NULL DEFAULT 1,
                applied_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_migrations_migration (migration)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    private function nextBatch(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations');
        return $stmt === false ? 1 : (int) $stmt->fetchColumn();
    }

    private function studly(string $value): string
    {
        $value = (string) preg_replace('#^\d+_#', '', $value); // "001_create_suppliers" → "create_suppliers"
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $value))); // → CreateSuppliers
    }
}
