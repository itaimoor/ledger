<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use PDO;

final class EntryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * One page of the book, newest first, each row carrying the balance as of that entry.
     *
     * The window function runs inside a CTE over the project's entire book, before any
     * filter or cursor is applied. That is the whole point: a running balance is a
     * property of the entry's position in the book, not of the query that fetched it, so
     * page 2 shows the same figure page 1 would have shown for the same row, and a
     * category filter does not renumber history.
     *
     * ORDER BY inside the window is (entry_date, id) ascending; the page is served in the
     * exact reverse. The id tiebreak is what keeps several entries on one date stable —
     * without it the balances would shuffle between requests.
     *
     * ponytail: the CTE reads the project's whole book per page. At a few thousand entries
     * per project that is an indexed scan and costs nothing. If a project ever reaches the
     * hundreds of thousands, carry the opening balance in the cursor and sum forward from
     * there instead.
     *
     * @param array<string, mixed>               $filters
     * @param array{value: string, id: int}|null $cursor
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $orgId, int $projectId, array $filters, ?array $cursor, int $limit): array
    {
        $conditions = [];
        $bindings = ['org_id' => $orgId, 'project_id' => $projectId];

        // Used to re-read a single entry with its running balance intact after a write.
        if (isset($filters['id'])) {
            $conditions[] = 'b.id = :id';
            $bindings['id'] = (int) $filters['id'];
        }

        if (isset($filters['type'])) {
            $conditions[] = 'b.type = :type';
            $bindings['type'] = $filters['type'];
        }

        if (isset($filters['category_id'])) {
            $conditions[] = 'b.category_id = :category_id';
            $bindings['category_id'] = $filters['category_id'];
        }

        if (isset($filters['from'])) {
            $conditions[] = 'b.entry_date >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (isset($filters['to'])) {
            $conditions[] = 'b.entry_date <= :to';
            $bindings['to'] = $filters['to'];
        }

        if (isset($filters['search'])) {
            $conditions[] = 'b.description LIKE :search';
            $bindings['search'] = '%' . addcslashes((string) $filters['search'], '%_\\') . '%';
        }

        if ($cursor !== null) {
            // Strictly after the cursor row in (entry_date DESC, id DESC) order.
            //
            // Two placeholders for one value: with emulated prepares off, PDO rewrites
            // named parameters to positional ones and the driver rejects a name used
            // twice. Reusing :cursor_date here fails only once a cursor is supplied,
            // which is exactly the path a first-page smoke test never reaches.
            $conditions[] = '(b.entry_date < :cursor_date_before'
                . ' OR (b.entry_date = :cursor_date_same AND b.id < :cursor_id))';
            $bindings['cursor_date_before'] = $cursor['value'];
            $bindings['cursor_date_same'] = $cursor['value'];
            $bindings['cursor_id'] = $cursor['id'];
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $statement = $this->pdo->prepare(
            "WITH book AS (
                 SELECT e.id, e.entry_date, e.type, e.amount_paisa, e.category_id, e.description,
                        e.reconciles_entry_id, e.created_by, e.created_at,
                        SUM(CASE WHEN e.type = 'in' THEN e.amount_paisa ELSE -e.amount_paisa END)
                            OVER (ORDER BY e.entry_date, e.id ROWS UNBOUNDED PRECEDING)
                            AS running_balance_paisa
                 FROM entries e
                 WHERE e.org_id = :org_id AND e.project_id = :project_id AND e.deleted_at IS NULL
             )
             SELECT b.id, b.entry_date, b.type, b.amount_paisa, b.category_id, b.description,
                    b.reconciles_entry_id, b.created_by, b.created_at, b.running_balance_paisa,
                    c.name AS category_name,
                    u.name AS created_by_name,
                    EXISTS (
                        SELECT 1 FROM entries r
                        WHERE r.reconciles_entry_id = b.id AND r.deleted_at IS NULL
                    ) AS is_reconciled
             FROM book b
             LEFT JOIN categories c ON c.id = b.category_id
             JOIN users u ON u.id = b.created_by
             {$where}
             ORDER BY b.entry_date DESC, b.id DESC
             LIMIT :row_limit"
        );

        foreach ($bindings as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        // Native prepares will not accept an integer LIMIT bound as a string.
        $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $orgId, int $projectId, int $entryId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, org_id, project_id, type, amount_paisa, category_id, description,
                    entry_date, reconciles_entry_id, created_by, created_at
             FROM entries
             WHERE org_id = ? AND project_id = ? AND id = ? AND deleted_at IS NULL'
        );
        $statement->execute([$orgId, $projectId, $entryId]);

        return $statement->fetch() ?: null;
    }

    /**
     * Resolves an entry from its id alone, scoped to the organizations the caller belongs
     * to. Used by /entries/{id}/reconcile, where the URL names no project.
     *
     * @return array<string, mixed>|null
     */
    public function findForUser(int $entryId, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.org_id, e.project_id, e.type, e.amount_paisa, e.category_id,
                    e.description, e.entry_date, e.reconciles_entry_id, e.created_by,
                    m.role, p.status AS project_status, p.name AS project_name
             FROM entries e
             JOIN projects p ON p.org_id = e.org_id AND p.id = e.project_id AND p.deleted_at IS NULL
             JOIN organizations o ON o.id = e.org_id AND o.deleted_at IS NULL
             JOIN organization_members m ON m.org_id = e.org_id AND m.user_id = ?
             WHERE e.id = ? AND e.deleted_at IS NULL'
        );
        $statement->execute([$userId, $entryId]);

        return $statement->fetch() ?: null;
    }

    public function create(
        int $orgId,
        int $projectId,
        string $type,
        int $amountPaisa,
        ?int $categoryId,
        ?string $description,
        string $entryDate,
        ?int $reconcilesEntryId,
        int $createdBy,
    ): int {
        $this->pdo
            ->prepare(
                'INSERT INTO entries
                    (org_id, project_id, type, amount_paisa, category_id, description,
                     entry_date, reconciles_entry_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )
            ->execute([
                $orgId,
                $projectId,
                $type,
                $amountPaisa,
                $categoryId,
                $description,
                $entryDate,
                $reconcilesEntryId,
                $createdBy,
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function hasReconciliation(int $orgId, int $entryId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM entries WHERE org_id = ? AND reconciles_entry_id = ? AND deleted_at IS NULL'
        );
        $statement->execute([$orgId, $entryId]);

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string, mixed> */
    public function summary(int $orgId, int $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN type = \'in\'  THEN amount_paisa END), 0) AS total_in_paisa,
                    COALESCE(SUM(CASE WHEN type = \'out\' THEN amount_paisa END), 0) AS total_out_paisa,
                    COALESCE(SUM(type = \'in\'), 0)  AS in_count,
                    COALESCE(SUM(type = \'out\'), 0) AS out_count,
                    COUNT(*) AS entry_count,
                    MIN(entry_date) AS first_entry_date,
                    MAX(entry_date) AS last_entry_date
             FROM entries
             WHERE org_id = ? AND project_id = ? AND deleted_at IS NULL'
        );
        $statement->execute([$orgId, $projectId]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return [
            'total_in_paisa' => (int) $row['total_in_paisa'],
            'total_out_paisa' => (int) $row['total_out_paisa'],
            'balance_paisa' => (int) $row['total_in_paisa'] - (int) $row['total_out_paisa'],
            'in_count' => (int) $row['in_count'],
            'out_count' => (int) $row['out_count'],
            'entry_count' => (int) $row['entry_count'],
            'first_entry_date' => $row['first_entry_date'] === null ? null : (string) $row['first_entry_date'],
            'as_of' => $row['last_entry_date'] === null ? null : (string) $row['last_entry_date'],
        ];
    }
}
