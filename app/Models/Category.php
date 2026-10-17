<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Category entity.
 *
 * Tam yol: app/Models/Category.php
 */
final class Category
{
    public function __construct(
        public readonly ?int $id = null,
        public ?int $parentId = null,
        public string $name = '',
        public string $slug = '',
        public ?string $xmlCategoryCode = null,
        public int $isActive = 1,
        public int $sortOrder = 0
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            (string) ($row['name'] ?? ''),
            (string) ($row['slug'] ?? ''),
            isset($row['xml_category_code']) ? (string) $row['xml_category_code'] : null,
            (int) ($row['is_active'] ?? 1),
            (int) ($row['sort_order'] ?? 0)
        );
    }
}
