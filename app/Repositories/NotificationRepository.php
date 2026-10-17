<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * notifications tablosu erişimi (AŞAMA 5 — e-posta bildirim altyapısı).
 *
 * dedup_key UNIQUE → INSERT IGNORE ile tekrarlı bildirim veritabanı düzeyinde engellenir.
 *
 * Tam yol: app/Repositories/NotificationRepository.php
 */
final class NotificationRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'notifications';
    }

    /**
     * Bildirimi kuyruğa yazar. Aynı dedup_key zaten varsa sessizce atlanır.
     *
     * @return bool true = kuyruğa eklendi (ilk kez), false = zaten vardı (dedup)
     */
    public function enqueueOnce(
        string $event,
        string $recipientEmail,
        string $subject,
        string $body,
        ?string $refType,
        ?int $refId,
        string $dedupKey
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO notifications
             (event, recipient_email, subject, body, ref_type, ref_id, dedup_key, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, "pending", NOW(), NOW())'
        );
        $stmt->execute([
            $event,
            mb_substr($recipientEmail, 0, 180, 'UTF-8'),
            mb_substr($subject, 0, 255, 'UTF-8'),
            $body,
            $refType,
            $refId,
            mb_substr($dedupKey, 0, 160, 'UTF-8'),
        ]);
        return $stmt->rowCount() > 0;
    }

    /** Dedup anahtarına göre bildirim kaydı. */
    public function findByDedupKey(string $dedupKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE dedup_key = ? LIMIT 1');
        $stmt->execute([mb_substr($dedupKey, 0, 160, 'UTF-8')]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Gönderilecek bekleyen bildirimler (en fazla N deneme). @return list<array<string,mixed>> */
    public function pending(int $limit = 50, int $maxAttempts = 5): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications
             WHERE status = "pending" AND attempts < ?
             ORDER BY id ASC LIMIT ?'
        );
        $stmt->bindValue(1, $maxAttempts, \PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }

    public function markSending(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET status = "sending", attempts = attempts + 1, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function markSent(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE notifications SET status = "sent", sent_at = NOW(), last_error = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** Gönderim hatası: deneme sayısı eşik altındaysa tekrar denenecek (pending). */
    public function markFailed(int $id, string $error, int $maxAttempts = 5): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notifications SET status = IF(attempts >= ?, "failed", "pending"), last_error = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$maxAttempts, mb_substr($error, 0, 500, 'UTF-8'), $id]);
    }

    /** @return array<string,int> */
    public function countsByStatus(): array
    {
        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS c FROM notifications GROUP BY status');
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /** Admin/rapor için son kayıtlar. @return list<array<string,mixed>> */
    public function recent(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }
}
