<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use PDO;

final class CategoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Usage is returned with the list because the design shows entry count and total out
     * next to every category — an admin decides whether to delete one by looking at them.
     *
     * @return list<array<string, mixed>>
     */
    public function listForOrg(int $orgId, bool $includeArchived): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.name, c.type, c.is_archived,
                    COUNT(e.id) AS entry_count,
                    COALESCE(SUM(CASE WHEN e.type = \'out\' THEN e.amount_paisa END), 0) AS total_out_paisa
             FROM categories c
             LEFT JOIN entries e
                    ON e.org_id = c.org_id AND e.category_id = c.id AND e.deleted_at IS NULL
             WHERE c.org_id = ?' . ($includeArchived ? '' : ' AND c.is_archived = 0') . '
             GROUP BY c.id
             ORDER BY entry_count DESC, c.name ASC'
        );
        $statement->execute([$orgId]);

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $orgId, int $categoryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, org_id, name, type, is_archived FROM categories WHERE org_id = ? AND id = ?'
        );
        $statement->execute([$orgId, $categoryId]);

        return $statement->fetch() ?: null;
    }

    public function create(int $orgId, string $name, string $type): int
    {
        $this->pdo
            ->prepare('INSERT INTO categories (org_id, name, type) VALUES (?, ?, ?)')
            ->execute([$orgId, $name, $type]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $changes column => value, already whitelisted by the service */
    public function update(int $orgId, int $categoryId, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => "{$column} = ?",
            array_keys($changes),
        ));

        $statement = $this->pdo->prepare(
            "UPDATE categories SET {$assignments} WHERE org_id = ? AND id = ?"
        );
        $statement->execute([...array_values($changes), $orgId, $categoryId]);
    }

    /** Only ever called for a category with no entries against it. */
    public function delete(int $orgId, int $categoryId): void
    {
        $this->pdo
            ->prepare('DELETE FROM categories WHERE org_id = ? AND id = ?')
            ->execute([$orgId, $categoryId]);
    }

    public function usageCount(int $orgId, int $categoryId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM entries WHERE org_id = ? AND category_id = ? AND deleted_at IS NULL'
        );
        $statement->execute([$orgId, $categoryId]);

        return (int) $statement->fetchColumn();
    }

    public function nameExists(int $orgId, string $name, ?int $exceptId = null): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM categories WHERE org_id = ? AND name = ? AND id <> ?'
        );
        $statement->execute([$orgId, $name, $exceptId ?? 0]);

        return $statement->fetchColumn() !== false;
    }

    /** @param list<array{0: string, 1: string}> $defaults name, type */
    public function createMany(int $orgId, array $defaults): void
    {
        $statement = $this->pdo->prepare('INSERT INTO categories (org_id, name, type) VALUES (?, ?, ?)');

        foreach ($defaults as [$name, $type]) {
            $statement->execute([$orgId, $name, $type]);
        }
    }
}
