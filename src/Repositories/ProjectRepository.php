<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use PDO;

final class ProjectRepository
{
    /**
     * ORDER BY cannot be parameterised, so the sort key is resolved through this map and
     * a request value that is not a key here never reaches the query.
     */
    private const SORT_COLUMNS = [
        // A bare alias is fine here; an expression built on one is not. MariaDB rejects
        // `last_entry_at IS NULL` with "reference to group function", because the alias
        // stands for MAX(). Sorting DESC already puts NULLs last, so the test is redundant
        // as well as illegal — a project with no entries sinks to the bottom either way.
        'last_activity' => 'last_entry_at DESC',
        'name' => 'p.name ASC',
        'balance' => 'balance_paisa DESC',
        'entries' => 'entry_count DESC',
        'created' => 'p.created_at DESC',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForOrg(int $orgId, ?string $status, ?string $search, string $sort): array
    {
        $conditions = ['p.org_id = ?', 'p.deleted_at IS NULL'];
        $bindings = [$orgId];

        if ($status !== null) {
            $conditions[] = 'p.status = ?';
            $bindings[] = $status;
        }

        if ($search !== null) {
            // The placeholder quotes the value, but % and _ inside it are still wildcards,
            // so a search for "50%" must not turn into a match-anything pattern.
            $pattern = '%' . addcslashes($search, '%_\\') . '%';
            $conditions[] = '(p.name LIKE ? OR p.client_name LIKE ?)';
            $bindings[] = $pattern;
            $bindings[] = $pattern;
        }

        $order = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['last_activity'];

        $statement = $this->pdo->prepare(
            'SELECT p.id, p.name, p.client_name, p.description, p.status,
                    p.created_at, p.updated_at,
                    COALESCE(SUM(CASE WHEN e.type = \'in\'  THEN e.amount_paisa END), 0) AS total_in_paisa,
                    COALESCE(SUM(CASE WHEN e.type = \'out\' THEN e.amount_paisa END), 0) AS total_out_paisa,
                    COALESCE(SUM(CASE WHEN e.type = \'in\'  THEN e.amount_paisa
                                      ELSE -e.amount_paisa END), 0) AS balance_paisa,
                    COUNT(e.id) AS entry_count,
                    MAX(e.created_at) AS last_entry_at
             FROM projects p
             LEFT JOIN entries e
                    ON e.org_id = p.org_id AND e.project_id = p.id AND e.deleted_at IS NULL
             WHERE ' . implode(' AND ', $conditions) . '
             GROUP BY p.id
             ORDER BY ' . $order . ', p.id DESC'
        );
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    /**
     * The tenant gate for routes addressed by project id alone. Returns the project and
     * the caller's role in one query, or null if they have no business seeing it.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $projectId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.org_id, p.name, p.status, m.role
             FROM projects p
             JOIN organizations o ON o.id = p.org_id AND o.deleted_at IS NULL
             JOIN organization_members m ON m.org_id = p.org_id AND m.user_id = ?
             WHERE p.id = ? AND p.deleted_at IS NULL'
        );
        $statement->execute([$userId, $projectId]);

        return $statement->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $orgId, int $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, org_id, name, client_name, description, status, created_by, created_at, updated_at
             FROM projects
             WHERE org_id = ? AND id = ? AND deleted_at IS NULL'
        );
        $statement->execute([$orgId, $projectId]);

        return $statement->fetch() ?: null;
    }

    public function create(
        int $orgId,
        string $name,
        ?string $clientName,
        ?string $description,
        string $status,
        int $createdBy,
    ): int {
        $this->pdo
            ->prepare(
                'INSERT INTO projects (org_id, name, client_name, description, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )
            ->execute([$orgId, $name, $clientName, $description, $status, $createdBy]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $changes column => value, already whitelisted by the service */
    public function update(int $orgId, int $projectId, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $assignments = implode(', ', array_map(
            static fn (string $column): string => "{$column} = ?",
            array_keys($changes),
        ));

        $statement = $this->pdo->prepare(
            "UPDATE projects SET {$assignments} WHERE org_id = ? AND id = ? AND deleted_at IS NULL"
        );
        $statement->execute([...array_values($changes), $orgId, $projectId]);
    }

    public function softDelete(int $orgId, int $projectId): void
    {
        $this->pdo
            ->prepare('UPDATE projects SET deleted_at = NOW() WHERE org_id = ? AND id = ? AND deleted_at IS NULL')
            ->execute([$orgId, $projectId]);
    }

    /** @return array<string, int> */
    public function orgTotals(int $orgId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN e.type = \'in\'  THEN e.amount_paisa END), 0) AS total_in_paisa,
                    COALESCE(SUM(CASE WHEN e.type = \'out\' THEN e.amount_paisa END), 0) AS total_out_paisa,
                    COUNT(e.id) AS entry_count
             FROM entries e
             JOIN projects p ON p.org_id = e.org_id AND p.id = e.project_id AND p.deleted_at IS NULL
             WHERE e.org_id = ? AND e.deleted_at IS NULL'
        );
        $statement->execute([$orgId]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return [
            'total_in_paisa' => (int) $row['total_in_paisa'],
            'total_out_paisa' => (int) $row['total_out_paisa'],
            'balance_paisa' => (int) $row['total_in_paisa'] - (int) $row['total_out_paisa'],
            'entry_count' => (int) $row['entry_count'],
        ];
    }

    /** @return array<string, int> counts keyed by status, for the filter chips */
    public function statusCounts(int $orgId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS n FROM projects
             WHERE org_id = ? AND deleted_at IS NULL GROUP BY status'
        );
        $statement->execute([$orgId]);

        $counts = ['active' => 0, 'completed' => 0, 'archived' => 0];

        foreach ($statement->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }
}
