<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Supplier entity.
 *
 * Tam yol: app/Models/Supplier.php
 */
final class Supplier
{
    public const STATUS_ACTIVE = 1;
    public const STATUS_PASSIVE = 0;

    public function __construct(
        public readonly ?int $id = null,
        public string $name = '',
        public string $code = '',
        public ?string $contactName = null,
        public ?string $email = null,
        public ?string $phone = null,
        public int $isActive = self::STATUS_ACTIVE,
        public ?string $notes = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            isset($row['id']) ? (int) $row['id'] : null,
            (string) ($row['name'] ?? ''),
            (string) ($row['code'] ?? ''),
            isset($row['contact_name']) ? (string) $row['contact_name'] : null,
            isset($row['email']) ? (string) $row['email'] : null,
            isset($row['phone']) ? (string) $row['phone'] : null,
            (int) ($row['is_active'] ?? 1),
            isset($row['notes']) ? (string) $row['notes'] : null,
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['updated_at']) ? (string) $row['updated_at'] : null
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'code'         => $this->code,
            'contact_name' => $this->contactName,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'is_active'    => $this->isActive,
            'notes'        => $this->notes,
            'created_at'   => $this->createdAt,
            'updated_at'   => $this->updatedAt,
        ];
    }
}
