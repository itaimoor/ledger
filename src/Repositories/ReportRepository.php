<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use PDO;
use PDOStatement;

final class ReportRepository
{
    /**
     * DATE_FORMAT patterns, chosen by key. The pattern is bound as a value rather than
     * spliced into the SQL, and the key is whitelisted before it gets here.
     */
    public const INTERVALS = [
        'daily' => '%Y-%m-%d',
        'weekly' => '%x-W%v',
        'monthly' => '%Y-%m',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Money in and out per period, for the "In vs Out over time" chart.
     *
     * @param array<string, mixed> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function cashflow(int $orgId, string $interval, array $filters): array
    {
        [$where, $bindings] = $this->scope($orgId, $filters);
        $bindings['format'] = self::INTERVALS[$interval];

        $statement = $this->pdo->prepare(
            "SELECT DATE_FORMAT(e.entry_date, :format) AS period,
                    MIN(e.entry_date) AS period_start,
                    MAX(e.entry_date) AS period_end,
                    COALESCE(SUM(CASE WHEN e.type = 'in'  THEN e.amount_paisa END), 0) AS total_in_paisa,
                    COALESCE(SUM(CASE WHEN e.type = 'out' THEN e.amount_paisa END), 0) AS total_out_paisa,
                    COUNT(*) AS entry_count
             FROM entries e
             JOIN projects p ON p.org_id = e.org_id AND p.id = e.project_id AND p.deleted_at IS NULL
             {$where}
             GROUP BY period
             ORDER BY period"
        );

        $this->bind($statement, $bindings);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Money out grouped by category.
     *
     * A LEFT JOIN, not an inner one: a reconciling entry that corrects a receipt is money
     * out with no category, so an inner join would quietly drop it and the shares would
     * stop adding up to the total.
     *
     * @param array<string, mixed> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function outByCategory(int $orgId, array $filters): array
    {
        [$where, $bindings] = $this->scope($orgId, $filters, "e.type = 'out'");

        $statement = $this->pdo->prepare(
            "SELECT e.category_id, c.name AS category_name,
                    SUM(e.amount_paisa) AS total_out_paisa,
                    COUNT(*) AS entry_count
             FROM entries e
             JOIN projects p ON p.org_id = e.org_id AND p.id = e.project_id AND p.deleted_at IS NULL
             LEFT JOIN categories c ON c.org_id = e.org_id AND c.id = e.category_id
             {$where}
             GROUP BY e.category_id
             ORDER BY total_out_paisa DESC"
        );

        $this->bind($statement, $bindings);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, int>
     */
    public function totals(int $orgId, array $filters): array
    {
        [$where, $bindings] = $this->scope($orgId, $filters);

        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN e.type = 'in'  THEN e.amount_paisa END), 0) AS total_in_paisa,
                    COALESCE(SUM(CASE WHEN e.type = 'out' THEN e.amount_paisa END), 0) AS total_out_paisa,
                    COUNT(*) AS entry_count
             FROM entries e
             JOIN projects p ON p.org_id = e.org_id AND p.id = e.project_id AND p.deleted_at IS NULL
             {$where}"
        );

        $this->bind($statement, $bindings);
        $statement->execute();

        /** @var array<string, mixed> $row */
        $row = $statement->fetch();

        return [
            'total_in_paisa' => (int) $row['total_in_paisa'],
            'total_out_paisa' => (int) $row['total_out_paisa'],
            'balance_paisa' => (int) $row['total_in_paisa'] - (int) $row['total_out_paisa'],
            'entry_count' => (int) $row['entry_count'],
        ];
    }

    /**
     * Every report is scoped the same way, in one place: this organization, live entries,
     * live projects, and whatever narrowing the caller asked for.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function scope(int $orgId, array $filters, string ...$extra): array
    {
        $conditions = ['e.org_id = :org_id', 'e.deleted_at IS NULL', ...$extra];
        $bindings = ['org_id' => $orgId];

        if (isset($filters['project_id'])) {
            $conditions[] = 'e.project_id = :project_id';
            $bindings['project_id'] = (int) $filters['project_id'];
        }

        if (isset($filters['from'])) {
            $conditions[] = 'e.entry_date >= :from';
            $bindings['from'] = $filters['from'];
        }

        if (isset($filters['to'])) {
            $conditions[] = 'e.entry_date <= :to';
            $bindings['to'] = $filters['to'];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $bindings];
    }

    /** @param array<string, mixed> $bindings */
    private function bind(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
