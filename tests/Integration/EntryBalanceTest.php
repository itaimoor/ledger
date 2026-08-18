<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Domain\ProjectStatus;
use Ledger\Domain\Role;
use Ledger\Exceptions\HttpException;
use Ledger\Exceptions\ValidationException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Repositories\EntryRepository;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use Ledger\Security\ProjectContext;
use Ledger\Services\EntryService;

final class EntryBalanceTest extends DatabaseTestCase
{
    private EntryService $entries;

    private ProjectContext $project;

    private int $labourId;

    private int $receiptId;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::pdo();
        $activity = new ActivityLogRepository($pdo);
        $categories = new CategoryRepository($pdo);

        $this->entries = new EntryService(
            new EntryRepository($pdo),
            $categories,
            $activity,
            new Policy($activity),
        );

        $userId = $this->makeUser();

        $pdo->prepare('INSERT INTO organizations (name, slug, created_by) VALUES (?, ?, ?)')
            ->execute(['Rehman Builders (Pvt) Ltd', 'rehman-builders', $userId]);
        $orgId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$orgId, $userId]);

        $pdo->prepare("INSERT INTO projects (org_id, name, status, created_by) VALUES (?, ?, 'active', ?)")
            ->execute([$orgId, 'DHA Phase 6, Villa 214', $userId]);

        $this->project = new ProjectContext(
            new Membership($orgId, $userId, Role::Owner),
            (int) $pdo->lastInsertId(),
            'DHA Phase 6, Villa 214',
            ProjectStatus::Active,
        );

        $this->labourId = $categories->create($orgId, 'Labour', 'out');
        $this->receiptId = $categories->create($orgId, 'Client payment', 'in');
    }

    /** @return array<string, mixed> */
    private function add(string $type, int $rupees, string $date, ?string $description = null): array
    {
        return $this->entries->create($this->project, [
            'type' => $type,
            'amount_paisa' => $rupees * 100,
            'entry_date' => $date,
            'category_id' => $type === 'in' ? $this->receiptId : $this->labourId,
            'description' => $description ?? "{$type} {$rupees}",
        ], '127.0.0.1');
    }

    /** @return list<int> running balances, newest row first */
    private function balances(array $filters = [], ?string $cursor = null, int $limit = 50): array
    {
        return array_map(
            static fn (array $row): int => $row['running_balance_paisa'],
            $this->entries->list($this->project, $filters, $cursor, $limit)['data'],
        );
    }

    public function testRunningBalanceAccumulatesFromTheOldestEntryForward(): void
    {
        $this->add('in', 1_000_000, '2026-01-01');
        $this->add('out', 250_000, '2026-01-02');
        $this->add('out', 150_000, '2026-01-03');
        $this->add('in', 400_000, '2026-01-04');

        // Newest first: 1,000,000 -250,000 -150,000 +400,000
        self::assertSame(
            [100_000_000, 60_000_000, 75_000_000, 100_000_000],
            $this->balances(),
        );
    }

    public function testTheNewestRowCarriesTheProjectBalance(): void
    {
        $this->add('in', 1_000_000, '2026-01-01');
        $this->add('out', 250_000, '2026-01-02');

        self::assertSame(
            $this->entries->summary($this->project)['balance_paisa'],
            $this->balances()[0],
        );
    }

    public function testTheBalanceMayGoNegative(): void
    {
        $this->add('in', 100_000, '2026-01-01');
        $this->add('out', 260_000, '2026-01-02');

        self::assertSame(-16_000_000, $this->balances()[0]);
    }

    /**
     * The reason the sort carries an id tiebreak. Several entries on one date must have a
     * fixed order, or their balances shuffle between identical requests.
     */
    public function testEntriesSharingADateAreOrderedByIdAndTheirBalancesAreStable(): void
    {
        $this->add('in', 500_000, '2026-02-10');
        $this->add('out', 100_000, '2026-02-10');
        $this->add('out', 50_000, '2026-02-10');

        $expected = [35_000_000, 40_000_000, 50_000_000];

        self::assertSame($expected, $this->balances());
        self::assertSame($expected, $this->balances(), 'A repeated request must return the same order.');
    }

    public function testAnEntryBackdatedBeforeExistingOnesReordersTheBookCorrectly(): void
    {
        $this->add('in', 1_000_000, '2026-03-05');
        $this->add('out', 200_000, '2026-03-06');

        // Slotted in ahead of both, despite being written last.
        $this->add('in', 300_000, '2026-03-01');

        $rows = $this->entries->list($this->project, [], null, 50)['data'];

        self::assertSame(['2026-03-06', '2026-03-05', '2026-03-01'], array_column($rows, 'entry_date'));
        self::assertSame([110_000_000, 130_000_000, 30_000_000], array_column($rows, 'running_balance_paisa'));
    }

    /** The requirement: page 2 must continue the sequence, not restart it. */
    public function testRunningBalanceStaysCorrectAcrossPages(): void
    {
        for ($day = 1; $day <= 30; ++$day) {
            $this->add($day % 5 === 0 ? 'in' : 'out', $day * 1000, sprintf('2026-04-%02d', $day));
        }

        $wholeBook = $this->balances(limit: 50);
        self::assertCount(30, $wholeBook);

        $paged = [];
        $cursor = null;

        do {
            $page = $this->entries->list($this->project, [], $cursor, 7);
            $paged = [...$paged, ...array_column($page['data'], 'running_balance_paisa')];
            $cursor = $page['meta']['next_cursor'];
        } while ($cursor !== null);

        self::assertSame($wholeBook, $paged, 'Paged balances must match the single-page reading exactly.');
    }

    public function testPagingNeverRepeatsOrSkipsAnEntry(): void
    {
        for ($day = 1; $day <= 25; ++$day) {
            $this->add('out', 1000, sprintf('2026-05-%02d', $day));
        }

        $seen = [];
        $cursor = null;

        do {
            $page = $this->entries->list($this->project, [], $cursor, 4);
            $seen = [...$seen, ...array_column($page['data'], 'id')];
            $cursor = $page['meta']['next_cursor'];
        } while ($cursor !== null);

        self::assertCount(25, $seen);
        self::assertSame($seen, array_unique($seen));
    }

    public function testTheLastPageReportsNoFurtherCursor(): void
    {
        $this->add('in', 1000, '2026-06-01');
        $this->add('in', 1000, '2026-06-02');

        $page = $this->entries->list($this->project, [], null, 50);

        self::assertFalse($page['meta']['has_more']);
        self::assertNull($page['meta']['next_cursor']);
    }

    /**
     * A running balance is a property of the entry's place in the book, not of the query.
     * Filtering to one category must not renumber history.
     */
    public function testFilteringDoesNotRecomputeTheBalance(): void
    {
        $this->add('in', 1_000_000, '2026-07-01');
        $this->add('out', 250_000, '2026-07-02');
        $this->add('out', 150_000, '2026-07-03');

        $unfiltered = $this->entries->list($this->project, [], null, 50)['data'];
        $filtered = $this->entries->list($this->project, ['type' => 'out'], null, 50)['data'];

        $byId = array_column($unfiltered, 'running_balance_paisa', 'id');

        foreach ($filtered as $row) {
            self::assertSame($byId[$row['id']], $row['running_balance_paisa']);
        }
    }

    public function testAReconcilingEntryOffsetsTheOriginalInTheBalance(): void
    {
        $this->add('in', 1_000_000, '2026-08-01');
        $mistake = $this->add('out', 850_000, '2026-08-02', 'Cement — stray zero');

        self::assertSame(15_000_000, $this->balances()[0]);

        $this->entries->reconcile(
            $this->project,
            $mistake['id'],
            ['amount_paisa' => 765_000 * 100, 'entry_date' => '2026-08-03'],
            '127.0.0.1',
        );

        self::assertSame(91_500_000, $this->balances()[0]);
    }

    public function testBothHalvesOfAReconciliationStayInTheBook(): void
    {
        $mistake = $this->add('out', 100_000, '2026-08-02');
        $correction = $this->entries->reconcile($this->project, $mistake['id'], [], '127.0.0.1');

        $rows = $this->entries->list($this->project, [], null, 50)['data'];

        self::assertCount(2, $rows);
        self::assertSame('in', $correction['type'], 'A correction is the opposite direction.');
        self::assertSame($mistake['id'], $correction['reconciles_entry_id']);
        self::assertSame(0, $this->balances()[0], 'A full reversal returns the book to zero.');
    }

    public function testTheOriginalIsFlaggedOnceItHasBeenReconciled(): void
    {
        $mistake = $this->add('out', 100_000, '2026-08-02');
        self::assertFalse($mistake['is_reconciled']);

        $this->entries->reconcile($this->project, $mistake['id'], [], '127.0.0.1');

        $rows = array_column($this->entries->list($this->project, [], null, 50)['data'], 'is_reconciled', 'id');
        self::assertTrue($rows[$mistake['id']]);
    }

    public function testAnEntryCannotBeReconciledTwice(): void
    {
        $mistake = $this->add('out', 100_000, '2026-08-02');
        $this->entries->reconcile($this->project, $mistake['id'], [], '127.0.0.1');

        try {
            $this->entries->reconcile($this->project, $mistake['id'], [], '127.0.0.1');
            self::fail('Expected the second reconciliation to be rejected.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
        }
    }

    public function testACorrectionCannotExceedTheEntryItCorrects(): void
    {
        $mistake = $this->add('out', 100_000, '2026-08-02');

        $this->expectException(ValidationException::class);

        $this->entries->reconcile($this->project, $mistake['id'], ['amount_paisa' => 200_000 * 100], '127.0.0.1');
    }

    public function testMoneyOutRequiresACategory(): void
    {
        $this->expectException(ValidationException::class);

        $this->entries->create($this->project, [
            'type' => 'out',
            'amount_paisa' => 50_000,
            'entry_date' => '2026-08-02',
        ], '127.0.0.1');
    }

    public function testMoneyInDoesNotRequireACategory(): void
    {
        $entry = $this->entries->create($this->project, [
            'type' => 'in',
            'amount_paisa' => 50_000,
            'entry_date' => '2026-08-02',
        ], '127.0.0.1');

        self::assertNull($entry['category']);
    }

    public function testACategoryDeclaredForOutCannotBeUsedForIn(): void
    {
        $this->expectException(ValidationException::class);

        $this->entries->create($this->project, [
            'type' => 'in',
            'amount_paisa' => 50_000,
            'entry_date' => '2026-08-02',
            'category_id' => $this->labourId,
        ], '127.0.0.1');
    }

    public function testAnArchivedProjectRefusesNewEntries(): void
    {
        $archived = new ProjectContext(
            $this->project->membership,
            $this->project->projectId,
            $this->project->projectName,
            ProjectStatus::Archived,
        );

        try {
            $this->entries->create($archived, [
                'type' => 'in',
                'amount_paisa' => 50_000,
                'entry_date' => '2026-08-02',
            ], '127.0.0.1');
            self::fail('Expected an archived project to refuse the entry.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testAViewerCannotAddEntries(): void
    {
        $viewer = new ProjectContext(
            new Membership($this->project->membership->orgId, $this->project->membership->userId, Role::Viewer),
            $this->project->projectId,
            $this->project->projectName,
            ProjectStatus::Active,
        );

        $this->expectException(HttpException::class);

        $this->entries->create($viewer, [
            'type' => 'in',
            'amount_paisa' => 50_000,
            'entry_date' => '2026-08-02',
        ], '127.0.0.1');
    }

    public function testSummaryCountsReceiptsAndPaymentsSeparately(): void
    {
        $this->add('in', 1_000_000, '2026-09-01');
        $this->add('in', 500_000, '2026-09-02');
        $this->add('out', 250_000, '2026-09-03');

        $summary = $this->entries->summary($this->project);

        self::assertSame(150_000_000, $summary['total_in_paisa']);
        self::assertSame(25_000_000, $summary['total_out_paisa']);
        self::assertSame(125_000_000, $summary['balance_paisa']);
        self::assertSame(2, $summary['in_count']);
        self::assertSame(1, $summary['out_count']);
        self::assertSame('2026-09-03', $summary['as_of']);
        self::assertSame('2026-09-01', $summary['first_entry_date']);
    }

    public function testAnEmptyBookSummarisesAsZeroRatherThanNull(): void
    {
        $summary = $this->entries->summary($this->project);

        self::assertSame(0, $summary['total_in_paisa']);
        self::assertSame(0, $summary['balance_paisa']);
        self::assertNull($summary['as_of']);
        self::assertSame([], $this->entries->list($this->project, [], null, 50)['data']);
    }

    /** Amounts stay exact integers; nothing is ever routed through a float. */
    public function testLargeAmountsSurviveWithoutPrecisionLoss(): void
    {
        $huge = 92_233_720_368_547; // paisa, well past a float's exact-integer range
        $this->entries->create($this->project, [
            'type' => 'in',
            'amount_paisa' => $huge,
            'entry_date' => '2026-10-01',
        ], '127.0.0.1');

        self::assertSame($huge, $this->entries->summary($this->project)['total_in_paisa']);
        self::assertSame($huge, $this->balances()[0]);
    }
}
