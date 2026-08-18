<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Domain\Role;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\ProjectRepository;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use Ledger\Services\ProjectService;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every sort order has to actually execute.
 *
 * The default order was broken for a fortnight of development because the smoke tests all
 * passed an explicit ?sort=, and only the browser ever asked for the default.
 */
final class ProjectListingTest extends DatabaseTestCase
{
    private ProjectService $projects;

    private Membership $membership;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::pdo();
        $activity = new ActivityLogRepository($pdo);

        $this->projects = new ProjectService(new ProjectRepository($pdo), $activity, new Policy($activity));

        $userId = $this->makeUser();

        $pdo->prepare('INSERT INTO organizations (name, slug, created_by) VALUES (?, ?, ?)')
            ->execute(['Rehman Builders', 'rehman-builders', $userId]);
        $orgId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$orgId, $userId]);

        $this->membership = new Membership($orgId, $userId, Role::Owner);

        $busy = $this->projects->create($this->membership, ['name' => 'Busy project'], '127.0.0.1')['id'];
        $this->projects->create($this->membership, ['name' => 'Untouched project'], '127.0.0.1');

        $pdo->prepare(
            "INSERT INTO entries (org_id, project_id, type, amount_paisa, entry_date, created_by)
             VALUES (?, ?, 'in', 100000, '2026-01-01', ?)"
        )->execute([$orgId, $busy, $userId]);
    }

    #[DataProvider('sortOrders')]
    public function testEverySortOrderExecutes(string $sort): void
    {
        $result = $this->projects->list($this->membership, null, null, $sort);

        self::assertCount(2, $result['data']);
    }

    /** @return iterable<string, array{string}> */
    public static function sortOrders(): iterable
    {
        foreach (['last_activity', 'name', 'balance', 'entries', 'created'] as $sort) {
            yield $sort => [$sort];
        }

        yield 'unknown falls back' => ['no-such-sort'];
    }

    /** A project nobody has written in yet belongs at the bottom, not the top. */
    public function testProjectsWithNoEntriesSortLastByActivity(): void
    {
        $names = array_column($this->projects->list($this->membership, null, null, 'last_activity')['data'], 'name');

        self::assertSame(['Busy project', 'Untouched project'], $names);
    }

    public function testStatusCountsAndTotalsTravelInMeta(): void
    {
        $meta = $this->projects->list($this->membership, null, null, 'last_activity')['meta'];

        self::assertSame(2, $meta['count']);
        self::assertSame(['active' => 2, 'completed' => 0, 'archived' => 0], $meta['status_counts']);
        self::assertSame(100000, $meta['totals']['total_in_paisa']);
    }
}
