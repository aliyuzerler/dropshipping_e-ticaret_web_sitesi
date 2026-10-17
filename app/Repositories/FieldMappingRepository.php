<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * xml_field_mappings tablosu erişimi (alan eşleme profilleri).
 *
 * Tam yol: app/Repositories/FieldMappingRepository.php
 */
final class FieldMappingRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'xml_field_mappings';
    }

    /**
     * Feed'in aktif eşlemeleri (tanım sırasına göre).
     * @return list<array<string,mixed>>
     */
    public function forFeed(int $feedId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM xml_field_mappings WHERE xml_feed_id = ? AND is_active = 1 ORDER BY id'
        );
        $stmt->execute([$feedId]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }

    /**
     * Feed'in eşlemelerini topluca yeniler (profil kaydetme).
     * Format: list<array{source_path:string, target_field:string, field_type?:string, transform?:?string, default_value?:?string}>
     *
     * @param list<array<string,mixed>> $mappings
     */
    public function replaceForFeed(int $feedId, array $mappings): int
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM xml_field_mappings WHERE xml_feed_id = ?')->execute([$feedId]);

            $stmt = $this->pdo->prepare(
                'INSERT INTO xml_field_mappings
                    (xml_feed_id, source_path, target_field, field_type, transform, default_value, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
            );
            $count = 0;
            foreach ($mappings as $m) {
                $source = trim((string) ($m['source_path'] ?? ''));
                $target = trim((string) ($m['target_field'] ?? ''));
                if ($source === '' || $target === '') {
                    continue;
                }
                $stmt->execute([
                    $feedId,
                    $source,
                    $target,
                    (string) ($m['field_type'] ?? 'text') !== '' ? (string) ($m['field_type'] ?? 'text') : 'text',
                    ($m['transform'] ?? null) !== null && (string) $m['transform'] !== '' ? (string) $m['transform'] : null,
                    ($m['default_value'] ?? null) !== null && (string) $m['default_value'] !== '' ? (string) $m['default_value'] : null,
                ]);
                $count++;
            }
            $this->pdo->commit();
            return $count;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function deleteForFeed(int $feedId): void
    {
        $this->pdo->prepare('DELETE FROM xml_field_mappings WHERE xml_feed_id = ?')->execute([$feedId]);
    }
}
