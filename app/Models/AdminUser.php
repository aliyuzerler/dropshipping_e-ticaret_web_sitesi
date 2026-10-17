<?php

declare(strict_types=1);

namespace App\Models;

/**
 * AdminUser entity + rol sabitleri.
 *
 * Tam yol: app/Models/AdminUser.php
 */
final class AdminUser
{
    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_VIEWER = 'viewer';

    public static function roles(): array
    {
        return [self::ROLE_SUPERADMIN, self::ROLE_ADMIN, self::ROLE_VIEWER];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function fromRow(array $row): array
    {
        unset($row['password_hash']); // hash dışarı sızmaz
        return $row;
    }
}
