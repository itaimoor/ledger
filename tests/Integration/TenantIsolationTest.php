<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Auth\AuthenticatedUser;
use Ledger\Domain\Role;
use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Repositories\InviteRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Repositories\OrganizationRepository;
use Ledger\Repositories\ProjectRepository;
use Ledger\Repositories\RefreshTokenRepository;
use Ledger\Repositories\UserRepository;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use Ledger\Services\CategoryService;
use Ledger\Services\MemberService;
use Ledger\Services\OrganizationService;
use Ledger\Services\ProjectService;
use PDOException;

/**
 * A user of organization A must not read or write anything in organization B, and must
 * not be able to tell B's resources apart from ones that do not exist.
 */
final class TenantIsolationTest extends DatabaseTestCase
{
    private ProjectService $projects;

    private CategoryService $categories;

    private MemberService $members;

    private OrganizationService $organizations;

    private MembershipRepository $memberships;

    private Membership $rehman;

    private Membership $sheikh;

    private int $sheikhProjectId;

    private int $sheikhCategoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::pdo();
        $policy = new Policy(new ActivityLogRepository($pdo));
        $activity = new ActivityLogRepository($pdo);

        $this->memberships = new MembershipRepository($pdo);
        $projectRepository = new ProjectRepository($pdo);
        $categoryRepository = new CategoryRepository($pdo);

        $this->projects = new ProjectService($projectRepository, $activity, $policy);
        $this->categories = new CategoryService($categoryRepository, $activity, $policy);
        $this->organizations = new OrganizationService(
            new OrganizationRepository($pdo),
            $this->memberships,
            $categoryRepository,
            $activity,
            $policy,
            $pdo,
        );
        $this->members = new MemberService(
            $this->memberships,
            new UserRepository($pdo),
            new InviteRepository($pdo),
            new RefreshTokenRepository($pdo),
            $activity,
            $policy,
            $pdo,
            'http://localhost:8000',
            72,
        );

        $sana = $this->makeUser('sana@rehmanbuilders.pk');
        $imran = $this->makeUser('imran@sheikhassociates.pk');

        $rehmanId = $this->organizations->create(
            new AuthenticatedUser($sana, 'Sana Rehman', 'sana@rehmanbuilders.pk', false),
            'Rehman Builders (Pvt) Ltd',
            '127.0.0.1',
        )['id'];

        $sheikhId = $this->organizations->create(
            new AuthenticatedUser($imran, 'Imran Sheikh', 'imran@sheikhassociates.pk', false),
            'Sheikh Associates',
            '127.0.0.1',
        )['id'];

        $this->rehman = new Membership($rehmanId, $sana, Role::Owner);
        $this->sheikh = new Membership($sheikhId, $imran, Role::Owner);

        $this->sheikhProjectId = $this->projects->create(
            $this->sheikh,
            ['name' => 'Clifton Block 4, Beach House'],
            '127.0.0.1',
        )['id'];

        $this->sheikhCategoryId = $this->categories->create($this->sheikh, 'Marble', 'out', '127.0.0.1')['id'];
    }

    public function testReadingAnotherTenantsProjectIsNotFoundNotForbidden(): void
    {
        try {
            $this->projects->show($this->rehman, $this->sheikhProjectId);
            self::fail('Expected the cross-tenant read to fail.');
        } catch (HttpException $e) {
            self::assertSame(
                404,
                $e->status(),
                '403 would confirm the project exists. It must be indistinguishable from a bad id.',
            );
        }
    }

    public function testAnAbsentIdAndAnotherTenantsIdProduceIdenticalResponses(): void
    {
        $absent = null;
        $foreign = null;

        try {
            $this->projects->show($this->rehman, 987_654);
        } catch (HttpException $e) {
            $absent = $e->status() . '/' . $e->errorCode() . '/' . $e->getMessage();
        }

        try {
            $this->projects->show($this->rehman, $this->sheikhProjectId);
        } catch (HttpException $e) {
            $foreign = $e->status() . '/' . $e->errorCode() . '/' . $e->getMessage();
        }

        self::assertNotNull($absent);
        self::assertSame($absent, $foreign);
    }

    public function testUpdatingAnotherTenantsProjectIsRejectedAndChangesNothing(): void
    {
        try {
            $this->projects->update($this->rehman, $this->sheikhProjectId, ['name' => 'Stolen'], '127.0.0.1');
            self::fail('Expected the cross-tenant write to fail.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }

        self::assertSame(
            'Clifton Block 4, Beach House',
            $this->projects->show($this->sheikh, $this->sheikhProjectId)['name'],
        );
    }

    public function testDeletingAnotherTenantsProjectIsRejectedAndLeavesItAlive(): void
    {
        try {
            $this->projects->delete($this->rehman, $this->sheikhProjectId, '127.0.0.1');
            self::fail('Expected the cross-tenant delete to fail.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }

        self::assertSame(
            $this->sheikhProjectId,
            $this->projects->show($this->sheikh, $this->sheikhProjectId)['id'],
        );
    }

    public function testProjectListingNeverLeaksAcrossTenants(): void
    {
        $this->projects->create($this->rehman, ['name' => 'DHA Phase 6, Villa 214'], '127.0.0.1');

        self::assertSame(
            ['DHA Phase 6, Villa 214'],
            array_column($this->projects->list($this->rehman, null, null, 'name')['data'], 'name'),
        );
        self::assertSame(
            ['Clifton Block 4, Beach House'],
            array_column($this->projects->list($this->sheikh, null, null, 'name')['data'], 'name'),
        );
    }

    public function testSearchCannotBeUsedToProbeAnotherTenant(): void
    {
        $result = $this->projects->list($this->rehman, null, 'Clifton', 'name');

        self::assertSame([], $result['data']);
        self::assertSame(0, $result['meta']['count']);
    }

    public function testAWildcardSearchIsTreatedAsLiteralText(): void
    {
        $this->projects->create($this->rehman, ['name' => '50% advance job'], '127.0.0.1');
        $this->projects->create($this->rehman, ['name' => 'Ordinary job'], '127.0.0.1');

        // If % leaked through as a wildcard, this would match both rows.
        self::assertSame(
            ['50% advance job'],
            array_column($this->projects->list($this->rehman, null, '50%', 'name')['data'], 'name'),
        );
    }

    public function testCategoriesAreScopedToTheTenant(): void
    {
        try {
            $this->categories->update($this->rehman, $this->sheikhCategoryId, ['name' => 'Taken'], '127.0.0.1');
            self::fail('Expected the cross-tenant category write to fail.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }

        self::assertNotContains(
            'Marble',
            array_column($this->categories->list($this->rehman, true), 'name'),
        );
    }

    public function testMemberListingShowsOnlyTheTenantsOwnPeople(): void
    {
        self::assertSame(
            ['sana@rehmanbuilders.pk'],
            array_column($this->members->list($this->rehman)['members'], 'email'),
        );
        self::assertSame(
            ['imran@sheikhassociates.pk'],
            array_column($this->members->list($this->sheikh)['members'], 'email'),
        );
    }

    public function testRemovingAMemberOfAnotherTenantIsNotFound(): void
    {
        try {
            $this->members->remove($this->rehman, $this->sheikh->userId, '127.0.0.1');
            self::fail('Expected the cross-tenant member removal to fail.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }

        self::assertNotNull($this->memberships->find($this->sheikh->orgId, $this->sheikh->userId));
    }

    /**
     * The application scopes every query, but the schema refuses the write independently.
     * If a repository ever forgot its org_id, this constraint would still stop the row.
     */
    public function testTheDatabaseItselfRefusesACrossTenantEntry(): void
    {
        $this->expectException(PDOException::class);

        self::pdo()
            ->prepare(
                'INSERT INTO entries (org_id, project_id, type, amount_paisa, entry_date, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )
            ->execute([
                $this->rehman->orgId,
                $this->sheikhProjectId,
                'out',
                100000,
                '2026-08-17',
                $this->rehman->userId,
            ]);
    }

    public function testOrganizationListingShowsOnlyMemberships(): void
    {
        self::assertSame(
            ['Rehman Builders (Pvt) Ltd'],
            array_column($this->organizations->listForUser($this->rehman->userId), 'name'),
        );
    }
}
