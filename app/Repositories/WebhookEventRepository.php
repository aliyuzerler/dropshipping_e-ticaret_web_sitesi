<?php

declare(strict_types=1);

namespace App\Repositories;

use PDOException;

/**
 * webhook_events tablosu erişimi (AŞAMA 4).
 * UNIQUE(provider, external_id) → tekrarlı webhook bildirimleri idempotent ele alınır.
 *
 * Tam yol: app/Repositories/WebhookEventRepository.php
 */
final class WebhookEventRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'webhook_events';
    }

    /**
     * Yeni olay kaydı; tekrarlı bildirimde false döner (ikinci işleme engellenir).
     *
     * @return bool true = ilk kez alındı, false = DUPLİKE bildirim
     */
    public function recordOnce(
        string $provider,
        string $eventType,
        string $externalId,
        string $payloadJson,
        bool $signatureValid
    ): bool {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO webhook_events (provider, event_type, external_id, payload, signature_valid, status, created_at)
                 VALUES (?, ?, ?, ?, ?, "received", NOW())'
            );
            $stmt->execute([$provider, $eventType, $externalId, $payloadJson, $signatureValid ? 1 : 0]);
            return true;
        } catch (PDOException $e) {
            // 23000/1062: unique ihlali → aynı bildirim zaten işlendi
            if ((string) $e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    public function markProcessed(int $id, string $status, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE webhook_events SET status = ?, processed_at = NOW(), error_message = ? WHERE id = ?'
        );
        $stmt->execute([$status, $error, $id]);
    }

    /** Son webhook olayları (admin). @return list<array<string,mixed>> */
    public function recent(int $limit = 30): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webhook_events ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        return $stmt->fetchAll();
    }
}
