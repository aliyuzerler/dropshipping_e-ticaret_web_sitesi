<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Core\Database;

/**
 * Health-check servisi: DB + PHP eklenti + depolama kontrolü.
 * Yalnız boolean sonuçlar döndürür — sürüm/host/kimlik bilgisi sızmaz.
 *
 * Tam yol: app/Services/Health/HealthService.php
 */
final class HealthService
{
    /**
     * @return array{status:string, checks:array<string,bool>, time:string}
     */
    public function check(): array
    {
        $checks = [
            'php_version'   => version_compare(PHP_VERSION, '8.2.0', '>='),
            'pdo_mysql'     => extension_loaded('pdo_mysql'),
            'xmlreader'     => extension_loaded('xmlreader'),
            'curl'          => extension_loaded('curl'),
            'fileinfo'      => extension_loaded('fileinfo'),
            'mbstring'      => extension_loaded('mbstring'),
            'openssl'       => extension_loaded('openssl'),
            'json'          => extension_loaded('json'),
            'storage_write' => $this->storageWritable(),
            'database'      => $this->databaseOk(),
        ];

        $allOk = !in_array(false, $checks, true);

        return [
            'status' => $allOk ? 'ok' : 'degraded',
            'checks' => $checks,
            'time'   => date('c'),
        ];
    }

    private function storageWritable(): bool
    {
        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            return false;
        }
        $probe = $dir . '/.health_probe_' . getmypid();
        $ok = @file_put_contents($probe, '1') !== false;
        if ($ok) {
            @unlink($probe);
        }
        return $ok;
    }

    private function databaseOk(): bool
    {
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->query('SELECT 1');
            return $stmt !== false && $stmt->fetchColumn() === 1;
        } catch (\Throwable) {
            return false;
        }
    }
}
