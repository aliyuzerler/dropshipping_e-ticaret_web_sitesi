<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * categories tablosu erişimi.
 *
 * Tam yol: app/Repositories/CategoryRepository.php
 */
final class CategoryRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'categories';
    }

    /**
     * Ada göre bulur (büyük/küçük harf duyarsız, ilk eşleşme).
     * @return array<string,mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE LOWER(name) = LOWER(?) ORDER BY id LIMIT 1');
        $stmt->execute([trim($name)]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Ada göre kategori bulur; yoksa oluşturur (import sırasında kategori senkronu).
     * @return array<string,mixed>
     */
    public function ensureByName(string $name, ?int $parentId = null): array
    {
        $name = trim($name);
        if ($name === '') {
            $name = 'Diğer';
        }
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return $existing;
        }
        $slug = $this->uniqueSlug($this->slugify($name));
        $this->insert([
            'parent_id'  => $parentId,
            'name'       => $name,
            'slug'       => $slug,
            'is_active'  => 1,
            'sort_order' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $found = $this->findByName($name);
        return $found ?? [];
    }

    /** id → parent_id haritası (fiyat kuralı kategori zinciri için). @return array<int,int|null> */
    public function parentMap(): array
    {
        $stmt = $this->pdo->query('SELECT id, parent_id FROM categories');
        $map = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $map[(int) $row['id']] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            }
        }
        return $map;
    }

    /** @return list<array<string,mixed>> */
    public function allOrdered(): array
    {
        $stmt = $this->pdo->query('SELECT id, parent_id, name, slug FROM categories WHERE is_active = 1 ORDER BY name');
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt === false ? [] : $stmt->fetchAll();
        return $rows;
    }

    private function slugify(string $text): string
    {
        $map = ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
                'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'I' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u'];
        $text = strtr($text, $map);
        $text = strtolower($text);
        $text = (string) preg_replace('~[^a-z0-9]+~', '-', $text);
        return trim($text, '-') !== '' ? trim($text, '-') : 'kategori';
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $i = 1;
        while (true) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM categories WHERE slug = ?');
            $stmt->execute([$slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
            $slug = $base . '-' . (++$i);
        }
    }
}
