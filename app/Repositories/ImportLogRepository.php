<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * xml_import_logs tablosu erişimi.
 *
 * Tam yol: app/Repositories/ImportLogRepository.php
 */
final class ImportLogRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'xml_import_logs';
    }

    /**
     * @param array{id:?int, xml_feed_id:?int, supplier_id:?int, file_name:string, source:string} $feed
     */
    public function start(array $feed): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO xml_import_logs (xml_feed_id, supplier_id, file_name, source, status, started_at)
             VALUES (?, ?, ?, ?, "running", NOW())'
        );
        $stmt->execute([
            $feed['xml_feed_id'],
            $feed['supplier_id'],
            $feed['file_name'],
            $feed['source'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Akış ilerlemesi: aktarım sürerken sayaçları günceller (progress ekranı için).
     *
     * @param array{total_rows?:int, created_rows?:int, updated_rows?:int, skipped_rows?:int, error_rows?:int} $counters
     */
    public function progress(int $logId, array $counters): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE xml_import_logs SET
                total_rows   = COALESCE(?, total_rows),
                created_rows = COALESCE(?, created_rows),
                updated_rows = COALESCE(?, updated_rows),
                skipped_rows = COALESCE(?, skipped_rows),
                error_rows   = COALESCE(?, error_rows)
             WHERE id = ?'
        );
        $stmt->execute([
            $counters['total_rows'] ?? null,
            $counters['created_rows'] ?? null,
            $counters['updated_rows'] ?? null,
            $counters['skipped_rows'] ?? null,
            $counters['error_rows'] ?? null,
            $logId,
        ]);
    }

    /**
     * @param array{status:string, total_rows?:int, created_rows?:int, updated_rows?:int,
     *              skipped_rows?:int, error_rows?:int, deactivated_rows?:int,
     *              error_message?:?string, warnings?:?string, duration_ms?:int, finished_at?:string} $summary
     */
    public function finish(int $logId, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE xml_import_logs
             SET status = ?, total_rows = COALESCE(?, total_rows), created_rows = COALESCE(?, created_rows),
                 updated_rows = COALESCE(?, updated_rows), skipped_rows = COALESCE(?, skipped_rows),
                 error_rows = COALESCE(?, error_rows), deactivated_rows = COALESCE(?, deactivated_rows),
                 error_message = ?, warnings = ?, duration_ms = ?, finished_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $summary['status'],
            $summary['total_rows'] ?? null,
            $summary['created_rows'] ?? null,
            $summary['updated_rows'] ?? null,
            $summary['skipped_rows'] ?? null,
            $summary['error_rows'] ?? null,
            $summary['deactivated_rows'] ?? null,
            $summary['error_message'] ?? null,
            $summary['warnings'] ?? null,
            $summary['duration_ms'] ?? null,
            $summary['finished_at'] ?? date('Y-m-d H:i:s'),
            $logId,
        ]);
    }

    public function touchFeedImported(int $feedId): void
    {
        $stmt = $this->pdo->prepare('UPDATE xml_feeds SET last_import_at = NOW() WHERE id = ?');
        $stmt->execute([$feedId]);
    }

    public function setFilePath(int $logId, string $relativePath): void
    {
        $this->pdo->prepare('UPDATE xml_import_logs SET file_path = ? WHERE id = ?')->execute([$relativePath, $logId]);
    }

    /**
     * Geçmiş listesi (opsiyonel feed + durum filtresi).
     * @return list<array<string,mixed>>
     */
    public function history(?int $feedId = null, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT l.*, f.name AS feed_name, s.name AS supplier_name
                FROM xml_import_logs l
                LEFT JOIN xml_feeds f ON f.id = l.xml_feed_id
                LEFT JOIN suppliers s ON s.id = l.supplier_id
                WHERE 1 = 1';
        $params = [];
        if ($feedId !== null) {
            $sql .= ' AND l.xml_feed_id = ?';
            $params[] = $feedId;
        }
        if ($status !== null && $status !== '') {
            $sql .= ' AND l.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY l.id DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Feed özet istatistikleri (dashboard/liste kolonları için).
     * @return array<string,int>|null
     */
    public function lastForFeed(int $feedId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM xml_import_logs WHERE xml_feed_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$feedId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Tek log kaydı + feed/tedarikçi adları (detay ekranı).
     * @return array<string,mixed>|null
     */
    public function findWithNames(int $logId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, f.name AS feed_name, s.name AS supplier_name
             FROM xml_import_logs l
             LEFT JOIN xml_feeds f ON f.id = l.xml_feed_id
             LEFT JOIN suppliers s ON s.id = l.supplier_id
             WHERE l.id = ? LIMIT 1'
        );
        $stmt->execute([$logId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}
