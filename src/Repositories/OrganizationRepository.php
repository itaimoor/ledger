<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use PDO;

final class OrganizationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $name, string $slug, int $createdBy): int
    {
        $this->pdo
            ->prepare('INSERT INTO organizations (name, slug, created_by) VALUES (?, ?, ?)')
            ->execute([$name, $slug, $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $orgId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, slug, created_by, created_at FROM organizations
             WHERE id = ? AND deleted_at IS NULL'
        );
        $statement->execute([$orgId]);

        return $statement->fetch() ?: null;
    }

    public function slugExists(string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM organizations WHERE slug = ?');
        $statement->execute([$slug]);

        return $statement->fetchColumn() !== false;
    }

    public function rename(int $orgId, string $name): void
    {
        $this->pdo
            ->prepare('UPDATE organizations SET name = ? WHERE id = ? AND deleted_at IS NULL')
            ->execute([$name, $orgId]);
    }

    /** Financial records are never destroyed; the organization is hidden from every query. */
    public function softDelete(int $orgId): void
    {
        $this->pdo
            ->prepare('UPDATE organizations SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL')
            ->execute([$orgId]);
    }
}
