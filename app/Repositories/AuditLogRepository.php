<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * audit_logs tablosu erişimi (AŞAMA 4 — denetim izi okuma).
 *
 * Tam yol: app/Repositories/AuditLogRepository.php
 */
final class AuditLogRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'audit_logs';
    }

    /** Varlığa ait denetim izleri (en yeni → eski). @return list<array<string,mixed>> */
    public function forEntity(string $entityType, int $entityId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, au.name AS admin_name FROM audit_logs a
             LEFT JOIN admin_users au ON au.id = a.admin_user_id
             WHERE a.entity_type = ? AND a.entity_id = ?
             ORDER BY a.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $entityType);
        $stmt->bindValue(2, $entityId, \PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    /** Son denetim kayıtları (admin özet). @return list<array<string,mixed>> */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, au.name AS admin_name FROM audit_logs a
             LEFT JOIN admin_users au ON au.id = a.admin_user_id
             ORDER BY a.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }
}
