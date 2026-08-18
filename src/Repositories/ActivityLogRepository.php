<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use PDO;

/** Not final: Policy's unit tests stub this out so the permission matrix needs no database. */
class ActivityLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        ?int $orgId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $ip = null,
    ): void {
        $this->pdo
            ->prepare(
                'INSERT INTO activity_log
                    (org_id, user_id, action, entity_type, entity_id, before_json, after_json, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )
            ->execute([
                $orgId,
                $userId,
                $action,
                $entityType,
                $entityId,
                $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
                $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
                $ip,
            ]);
    }

    /**
     * One page of the organization's audit trail, newest first.
     *
     * The project is resolved in the query rather than left to the client: for an entry
     * event it comes from the entry's own project, and for a project event from the
     * entity id. Both joins carry org_id so a forged entity_id cannot pull in a name from
     * another tenant.
     *
     * @param array<string, mixed>               $filters
     * @param array{value: string, id: int}|null $cursor
     *
     * @return list<array<string, mixed>>
     */
    public function listForOrg(int $orgId, array $filters, ?array $cursor, int $limit): array
    {
        $conditions = ['a.org_id = :org_id'];
        $bindings = ['org_id' => $orgId];

        if (isset($filters['user_id'])) {
            $conditions[] = 'a.user_id = :user_id';
            $bindings['user_id'] = (int) $filters['user_id'];
        }

        if (isset($filters['action'])) {
            $conditions[] = 'a.action = :action';
            $bindings['action'] = $filters['action'];
        }

        if (isset($filters['entity_type'])) {
            $conditions[] = 'a.entity_type = :entity_type';
            $bindings['entity_type'] = $filters['entity_type'];
        }

        if (isset($filters['from'])) {
            $conditions[] = 'a.created_at >= :from';
            $bindings['from'] = $filters['from'] . ' 00:00:00';
        }

        if (isset($filters['to'])) {
            $conditions[] = 'a.created_at <= :to';
            $bindings['to'] = $filters['to'] . ' 23:59:59';
        }

        if ($cursor !== null) {
            // Two placeholders for one value: with native prepares a named parameter
            // cannot appear twice in a statement.
            $conditions[] = '(a.created_at < :cursor_before'
                . ' OR (a.created_at = :cursor_same AND a.id < :cursor_id))';
            $bindings['cursor_before'] = $cursor['value'];
            $bindings['cursor_same'] = $cursor['value'];
            $bindings['cursor_id'] = $cursor['id'];
        }

        $where = implode(' AND ', $conditions);

        $statement = $this->pdo->prepare(
            "SELECT a.id, a.action, a.entity_type, a.entity_id,
                    a.before_json, a.after_json, a.created_at,
                    a.user_id, u.name AS actor_name,
                    p.id AS project_id, p.name AS project_name
             FROM activity_log a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN entries e
                    ON a.entity_type = 'entry' AND e.id = a.entity_id AND e.org_id = a.org_id
             LEFT JOIN projects p
                    ON p.org_id = a.org_id
                   AND p.id = CASE
                                  WHEN a.entity_type = 'entry' THEN e.project_id
                                  WHEN a.entity_type = 'project' THEN a.entity_id
                              END
             WHERE {$where}
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT :row_limit"
        );

        foreach ($bindings as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->bindValue(':row_limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}
