<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Migration sözleşmesi.
 * up(): yürütülecek SQL ifadelerini sırayla döndürür (tek transaction içinden çalıştırılır).
 *
 * Tam yol: app/Database/MigrationInterface.php
 */
interface MigrationInterface
{
    /** Migration kimliği (dosya adıyla aynı): örn. "001_create_suppliers" */
    public function name(): string;

    /** @return list<string> CREATE/ALTER SQL ifadeleri */
    public function up(): array;

    /** Geliştirme amaçlı geri alma. */
    public function down(\PDO $pdo): void;
}
