<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Domain\Role;
use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Security\Action;
use Ledger\Security\Membership;
use Ledger\Security\Policy;

final class PolicyDenialTest extends DatabaseTestCase
{
    private Policy $policy;

    private MembershipRepository $memberships;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new Policy(new ActivityLogRepository(self::pdo()));
        $this->memberships = new MembershipRepository(self::pdo());
    }

    public function testAuthorizeIsSilentWhenPermitted(): void
    {
        $this->expectNotToPerformAssertions();

        $this->policy->authorize(new Membership(1, 10, Role::Owner), Action::ManageOrganization);
    }

    public function testADenialThrows403AndIsRecorded(): void
    {
        $userId = $this->makeUser();
        $orgId = $this->makeOrganization($userId);

        try {
            $this->policy->authorize(
                new Membership($orgId, $userId, Role::Viewer),
                Action::ManageCategories,
                ip: '203.0.113.9',
            );
            self::fail('Expected the denial to throw.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
            self::assertSame('forbidden', $e->errorCode());
        }

        $row = self::pdo()
            ->query("SELECT org_id, user_id, ip, after_json FROM activity_log WHERE action = 'permission.denied'")
            ->fetch();

        self::assertNotFalse($row, 'A permission denial must leave an audit trail.');
        self::assertSame($orgId, (int) $row['org_id']);
        self::assertSame($userId, (int) $row['user_id']);
        self::assertSame('203.0.113.9', $row['ip']);
        self::assertStringContainsString('category.manage', (string) $row['after_json']);
        self::assertStringContainsString('viewer', (string) $row['after_json']);
    }

    public function testAMemberOfAnotherOrganizationHasNoMembership(): void
    {
        $sana = $this->makeUser('sana@rehmanbuilders.pk');
        $imran = $this->makeUser('imran@sheikhassociates.pk');

        $rehman = $this->makeOrganization($sana, 'Rehman Builders', 'rehman-builders');
        $sheikh = $this->makeOrganization($imran, 'Sheikh Associates', 'sheikh-associates');

        self::assertNotNull($this->memberships->find($rehman, $sana));
        self::assertNull(
            $this->memberships->find($sheikh, $sana),
            'Sana must have no standing at all in an organization she does not belong to.',
        );
        self::assertNull($this->memberships->find($rehman, $imran));
    }

    public function testAMissingOrganizationIsIndistinguishableFromOneYouCannotSee(): void
    {
        $userId = $this->makeUser();

        self::assertNull($this->memberships->find(999_999, $userId));
    }

    public function testASoftDeletedOrganizationStopsGrantingMembership(): void
    {
        $userId = $this->makeUser();
        $orgId = $this->makeOrganization($userId);

        self::assertNotNull($this->memberships->find($orgId, $userId));

        self::pdo()->prepare('UPDATE organizations SET deleted_at = NOW() WHERE id = ?')->execute([$orgId]);

        self::assertNull($this->memberships->find($orgId, $userId));
    }

    public function testListForUserReturnsOnlyTheirOwnOrganizations(): void
    {
        $sana = $this->makeUser('sana@rehmanbuilders.pk');
        $imran = $this->makeUser('imran@sheikhassociates.pk');

        $this->makeOrganization($sana, 'Rehman Builders', 'rehman-builders');
        $sheikh = $this->makeOrganization($imran, 'Sheikh Associates', 'sheikh-associates');

        self::pdo()
            ->prepare("INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, 'viewer')")
            ->execute([$sheikh, $sana]);

        self::assertSame(
            ['Rehman Builders', 'Sheikh Associates'],
            array_column($this->memberships->listForUser($sana), 'name'),
        );
        self::assertSame(
            ['Sheikh Associates'],
            array_column($this->memberships->listForUser($imran), 'name'),
        );
    }

    public function testTheRoleComesFromTheDatabaseNotTheCaller(): void
    {
        $userId = $this->makeUser();
        $orgId = $this->makeOrganization($userId);

        self::assertSame(Role::Owner, $this->memberships->find($orgId, $userId)?->role);

        self::pdo()
            ->prepare("UPDATE organization_members SET role = 'viewer' WHERE org_id = ? AND user_id = ?")
            ->execute([$orgId, $userId]);

        // No token reissue, no cache to bust: the demotion is effective on the next request.
        self::assertSame(Role::Viewer, $this->memberships->find($orgId, $userId)?->role);
    }

    private function makeOrganization(
        int $ownerId,
        string $name = 'Rehman Builders (Pvt) Ltd',
        string $slug = 'rehman-builders',
    ): int {
        $pdo = self::pdo();

        $pdo->prepare('INSERT INTO organizations (name, slug, created_by) VALUES (?, ?, ?)')
            ->execute([$name, $slug, $ownerId]);

        $orgId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$orgId, $ownerId]);

        return $orgId;
    }
}
