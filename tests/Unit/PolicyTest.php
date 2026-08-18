<?php

declare(strict_types=1);

namespace Ledger\Tests\Unit;

use Ledger\Domain\ProjectStatus;
use Ledger\Domain\Role;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Security\Action;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    private function policy(): Policy
    {
        // can() performs no I/O, so the collaborator is never touched on this path.
        return new Policy($this->createMock(ActivityLogRepository::class));
    }

    private function member(Role $role): Membership
    {
        return new Membership(orgId: 1, userId: 10, role: $role);
    }

    /**
     * The capability table from the design system, transcribed. Columns are
     * Owner, Admin, Accountant, Viewer — the same order the design prints them.
     *
     * @param array{bool, bool, bool, bool} $expected
     */
    #[DataProvider('capabilityTable')]
    public function testMatchesTheDesignedCapabilityTable(
        Action $action,
        ProjectStatus|Role|null $subject,
        array $expected,
    ): void {
        $roles = [Role::Owner, Role::Admin, Role::Accountant, Role::Viewer];

        foreach ($roles as $index => $role) {
            self::assertSame(
                $expected[$index],
                $this->policy()->can($this->member($role), $action, $subject),
                sprintf('%s for %s', $action->value, $role->value),
            );
        }
    }

    /** @return iterable<string, array{Action, ProjectStatus|Role|null, array{bool, bool, bool, bool}}> */
    public static function capabilityTable(): iterable
    {
        $active = ProjectStatus::Active;

        //                                                        owner  admin  acct   viewer
        yield 'View projects, entries and reports' =>
            [Action::ViewOrganization, null, [true, true, true, true]];

        // The design's "Export CSV and print" row has no case of its own. Export is built
        // in the browser from rows already served, so it is exactly ViewOrganization; a
        // separate permission would gate nothing while appearing to.

        yield 'Add entries' =>
            [Action::CreateEntry, $active, [true, true, true, false]];

        yield 'Add a reconciling entry against any entry' =>
            [Action::ReconcileEntry, $active, [true, true, true, false]];

        yield 'Edit an entry after saving' =>
            [Action::EditEntry, $active, [false, false, false, false]];

        yield 'Delete an entry' =>
            [Action::DeleteEntry, $active, [false, false, false, false]];

        yield 'Create and rename projects' =>
            [Action::CreateProject, null, [true, true, false, false]];

        yield 'Change project status, archive' =>
            [Action::UpdateProject, null, [true, true, false, false]];

        yield 'Delete a project' =>
            [Action::DeleteProject, null, [true, true, false, false]];

        yield 'Manage categories' =>
            [Action::ManageCategories, null, [true, true, false, false]];

        yield 'Invite members and change roles' =>
            [Action::ManageMembers, Role::Accountant, [true, true, false, false]];

        yield "Reset a member's password" =>
            [Action::ResetMemberPassword, Role::Accountant, [true, true, false, false]];

        yield 'Billing and delete organization' =>
            [Action::ManageOrganization, null, [true, false, false, false]];
    }

    #[DataProvider('everyRole')]
    public function testAnArchivedProjectIsReadOnlyForEveryRole(Role $role): void
    {
        $policy = $this->policy();
        $member = $this->member($role);

        self::assertFalse($policy->can($member, Action::CreateEntry, ProjectStatus::Archived));
        self::assertFalse($policy->can($member, Action::ReconcileEntry, ProjectStatus::Archived));
    }

    #[DataProvider('everyRole')]
    public function testAdminsAndOwnersCanStillReopenAnArchivedProject(Role $role): void
    {
        $expected = $role === Role::Owner || $role === Role::Admin;

        self::assertSame($expected, $this->policy()->can($this->member($role), Action::UpdateProject));
    }

    public function testACompletedProjectStillAcceptsEntries(): void
    {
        self::assertTrue(
            $this->policy()->can($this->member(Role::Accountant), Action::CreateEntry, ProjectStatus::Completed),
        );
    }

    /**
     * If a call site forgets the project, the answer must be no. An entry permission that
     * defaults to "allowed" would silently let entries into archived projects.
     */
    #[DataProvider('everyRole')]
    public function testEntryPermissionsFailClosedWithoutAProject(Role $role): void
    {
        self::assertFalse($this->policy()->can($this->member($role), Action::CreateEntry));
        self::assertFalse($this->policy()->can($this->member($role), Action::ReconcileEntry));
    }

    #[DataProvider('everyRole')]
    public function testNobodyMayManageTheOwnerThroughMemberManagement(Role $role): void
    {
        self::assertFalse($this->policy()->can($this->member($role), Action::ManageMembers, Role::Owner));
        self::assertFalse($this->policy()->can($this->member($role), Action::ResetMemberPassword, Role::Owner));
    }

    public function testAnOwnerMayStillManageEveryOtherRole(): void
    {
        $policy = $this->policy();
        $owner = $this->member(Role::Owner);

        foreach ([Role::Admin, Role::Accountant, Role::Viewer] as $target) {
            self::assertTrue($policy->can($owner, Action::ManageMembers, $target));
        }
    }

    public function testInvitingWithNoTargetIsAllowedForManagers(): void
    {
        self::assertTrue($this->policy()->can($this->member(Role::Admin), Action::ManageMembers));
        self::assertFalse($this->policy()->can($this->member(Role::Accountant), Action::ManageMembers));
    }

    /** Every action must be decided. A new case with no rule is a fatal error, not a silent allow. */
    public function testEveryActionIsDecidedForEveryRole(): void
    {
        $policy = $this->policy();

        foreach (Action::cases() as $action) {
            foreach (Role::cases() as $role) {
                self::assertIsBool($policy->can($this->member($role), $action, ProjectStatus::Active));
            }
        }
    }

    public function testOwnershipIsNeverInvitable(): void
    {
        self::assertFalse(Role::Owner->isInvitable());

        foreach ([Role::Admin, Role::Accountant, Role::Viewer] as $role) {
            self::assertTrue($role->isInvitable());
        }
    }

    /** @return iterable<string, array{Role}> */
    public static function everyRole(): iterable
    {
        foreach (Role::cases() as $role) {
            yield $role->value => [$role];
        }
    }
}
