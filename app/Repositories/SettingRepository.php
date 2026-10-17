<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * settings tablosu erişimi (key/value mağaza ayarları).
 *
 * Tam yol: app/Repositories/SettingRepository.php
 */
final class SettingRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'settings';
    }

    /** @return array<string,string> */
    public function allSettings(): array
    {
        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM settings');
        $map = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $map[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
            }
        }
        return $map;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public function set(string $key, ?string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        $stmt->execute([$key, $value]);
    }

    /**
     * Yalnızca henüz kayıtlı olmayan anahtarları yazar (seed güvenli).
     * @param array<string,string> $values
     */
    public function seedDefaults(array $values): int
    {
        $inserted = 0;
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO settings (setting_key, setting_value, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())'
        );
        foreach ($values as $key => $value) {
            $stmt->execute([$key, $value]);
            $inserted += $stmt->rowCount() > 0 ? 1 : 0;
        }
        return $inserted;
    }
}
