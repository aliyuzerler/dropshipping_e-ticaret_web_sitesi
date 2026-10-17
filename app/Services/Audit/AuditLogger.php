<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Core\Database;

/**
 * Denetim izi yazıcı (audit_logs tablosu). Asla istisna fırlatmaz —
 * audit kaydı uygulama akışını bozmamalı.
 *
 * Tam yol: app/Services/Audit/AuditLogger.php
 */
final class AuditLogger
{
    public static function log(
        string $action,
        ?int $adminUserId = null,
        string $ip = '',
        string $userAgent = '',
        ?string $entityType = null,
        ?int $entityId = null,
        array $context = [],
        string $actorType = 'admin'
    ): void {
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO audit_logs (admin_user_id, actor_type, action, entity_type, entity_id, ip_address, user_agent, context, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $adminUserId,
                $actorType,
                $action,
                $entityType,
                $entityId,
                $ip !== '' ? substr($ip, 0, 45) : null,
                $userAgent !== '' ? substr($userAgent, 0, 255) : null,
                $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) {
            // Audit hatası akışı bozmaz; app log'una düşer
            \App\Core\Logger::error('app', 'audit.write_failed', ['action' => $action, 'reason' => $e->getMessage()]);
        }
    }
}
