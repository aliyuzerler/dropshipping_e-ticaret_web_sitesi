<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Tüm repository'lerin tabanı. YALNIZCA prepared statements.
 *
 * Tam yol: app/Repositories/BaseRepository.php
 */
abstract class BaseRepository
{
    protected PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    abstract protected function table(): string;

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $data
     * @return int yeni id
     */
    public function insert(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO ' . $this->table() . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $column . ' = ?';
        }
        $sql = 'UPDATE ' . $this->table() . ' SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([...array_values($data), $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->table() . ' WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function count(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $this->table());
        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ' . $this->table() . ' ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }
}
