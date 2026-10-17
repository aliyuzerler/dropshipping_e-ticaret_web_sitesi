<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * xml_feeds tablosu erişimi.
 *
 * Tam yol: app/Repositories/FeedRepository.php
 */
final class FeedRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'xml_feeds';
    }

    /**
     * Aktif URL feed'leri (tam liste; zamanlamayı motor seçer).
     * @return list<array<string,mixed>>
     */
    public function activeUrlFeeds(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM xml_feeds WHERE is_active = 1 AND source_type = "url" ORDER BY id'
        );
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Aktif yükleme (upload) tipi feed'ler.
     * @return list<array<string,mixed>>
     */
    public function activeUploadFeeds(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM xml_feeds WHERE is_active = 1 AND source_type = "upload" ORDER BY id'
        );
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Zamanı gelen aktif URL feed'leri (interval_minutes bazlı).
     * @return list<array<string,mixed>>
     */
    public function dueUrlFeeds(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM xml_feeds
             WHERE is_active = 1 AND source_type = "url"
               AND (last_import_at IS NULL OR last_import_at <= DATE_SUB(NOW(), INTERVAL interval_minutes MINUTE))
             ORDER BY id'
        );
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Feed + tedarikçi adı (liste ekranları için).
     * @return list<array<string,mixed>>
     */
    public function listWithSupplier(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.*, s.name AS supplier_name, s.code AS supplier_code, c.name AS default_category_name
             FROM xml_feeds f
             INNER JOIN suppliers s ON s.id = f.supplier_id
             LEFT JOIN categories c ON c.id = f.default_category_id
             ORDER BY f.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    public function touchImported(int $feedId): void
    {
        $stmt = $this->pdo->prepare('UPDATE xml_feeds SET last_import_at = NOW() WHERE id = ?');
        $stmt->execute([$feedId]);
    }

    public function touchSuccessSync(int $feedId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE xml_feeds SET last_import_at = NOW(), last_success_sync_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$feedId]);
    }

    public function setLastFilePath(int $feedId, string $relativePath): void
    {
        $stmt = $this->pdo->prepare('UPDATE xml_feeds SET last_file_path = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$relativePath, $feedId]);
    }

    public function setActive(int $feedId, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE xml_feeds SET is_active = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $feedId]);
    }
}
