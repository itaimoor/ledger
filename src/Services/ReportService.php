<?php

declare(strict_types=1);

namespace Ledger\Services;

use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ProjectRepository;
use Ledger\Repositories\ReportRepository;
use Ledger\Security\Action;
use Ledger\Security\Membership;
use Ledger\Security\Policy;

final class ReportService
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ProjectRepository $projects,
        private readonly Policy $policy,
    ) {
    }

    /**
     * In versus out per period, plus the three readings the design puts under the chart:
     * the best period, the worst, and what a normal period costs.
     *
     * @param array<string, mixed> $filters
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function cashflow(Membership $membership, string $interval, array $filters): array
    {
        $this->policy->authorize($membership, Action::ViewOrganization);
        $this->assertProjectVisible($membership, $filters);

        $periods = array_map(
            static fn (array $row): array => [
                'period' => (string) $row['period'],
                'period_start' => (string) $row['period_start'],
                'period_end' => (string) $row['period_end'],
                'total_in_paisa' => (int) $row['total_in_paisa'],
                'total_out_paisa' => (int) $row['total_out_paisa'],
                'net_paisa' => (int) $row['total_in_paisa'] - (int) $row['total_out_paisa'],
                'entry_count' => (int) $row['entry_count'],
            ],
            $this->reports->cashflow($membership->orgId, $interval, $filters),
        );

        return [
            'data' => $periods,
            'meta' => [
                'interval' => $interval,
                'totals' => $this->reports->totals($membership->orgId, $filters),
            ] + $this->highlights($periods),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function outByCategory(Membership $membership, array $filters): array
    {
        $this->policy->authorize($membership, Action::ViewOrganization);
        $this->assertProjectVisible($membership, $filters);

        $rows = $this->reports->outByCategory($membership->orgId, $filters);

        $categories = array_map(
            static fn (array $row): array => [
                // Null is a reconciling entry correcting a receipt: money out with no
                // category, because it inherits its meaning from the entry it corrects.
                'category' => $row['category_id'] === null ? null : [
                    'id' => (int) $row['category_id'],
                    'name' => (string) $row['category_name'],
                ],
                'total_out_paisa' => (int) $row['total_out_paisa'],
                'entry_count' => (int) $row['entry_count'],
            ],
            $rows,
        );

        // The client draws the bars from these two numbers. Shares are not computed here:
        // a percentage is a presentation decision about rounding, not a fact about money.
        return [
            'data' => $categories,
            'meta' => [
                'total_out_paisa' => array_sum(array_column($categories, 'total_out_paisa')),
                'category_count' => count($categories),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $periods
     *
     * @return array<string, mixed>
     */
    private function highlights(array $periods): array
    {
        if ($periods === []) {
            return [
                'best_period' => null,
                'worst_period' => null,
                'average_out_paisa' => 0,
                'negative_period_count' => 0,
            ];
        }

        $nets = array_column($periods, 'net_paisa');
        $outs = array_column($periods, 'total_out_paisa');

        return [
            'best_period' => $periods[array_search(max($nets), $nets, true)],
            'worst_period' => $periods[array_search(min($nets), $nets, true)],
            // Integer division: an average of paisa is still paisa, never a fraction of one.
            'average_out_paisa' => intdiv((int) array_sum($outs), count($outs)),
            'negative_period_count' => count(array_filter($nets, static fn (int $net): bool => $net < 0)),
        ];
    }

    /**
     * A report narrowed to a project must prove the project is in this tenant, or the
     * report becomes a way to ask questions about someone else's book.
     *
     * @param array<string, mixed> $filters
     */
    private function assertProjectVisible(Membership $membership, array $filters): void
    {
        if (!isset($filters['project_id'])) {
            return;
        }

        if ($this->projects->find($membership->orgId, (int) $filters['project_id']) === null) {
            throw HttpException::notFound('No such project.');
        }
    }
}
