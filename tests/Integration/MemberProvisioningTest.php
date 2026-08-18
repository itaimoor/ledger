<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Domain\Role;
use Ledger\Exceptions\HttpException;
use Ledger\Exceptions\ValidationException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\InviteRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Repositories\RefreshTokenRepository;
use Ledger\Repositories\UserRepository;
use Ledger\Security\Membership;
use Ledger\Security\Policy;
use Ledger\Services\MemberService;
use PDO;

/**
 * Provisioning without email: an admin either creates the account outright and reads out a
 * one-time password, or hands over a single-use link.
 */
final class MemberProvisioningTest extends DatabaseTestCase
{
    private const INVITEE = 'nadia@rehmanbuilders.pk';
    private const PROVISIONED = 'usman@rehmanbuilders.pk';

    private MemberService $members;

    private MembershipRepository $memberships;

    private UserRepository $users;

    private Membership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::pdo();
        $activity = new ActivityLogRepository($pdo);

        $this->memberships = new MembershipRepository($pdo);
        $this->users = new UserRepository($pdo);

        $this->members = new MemberService(
            $this->memberships,
            $this->users,
            new InviteRepository($pdo),
            new RefreshTokenRepository($pdo),
            $activity,
            new Policy($activity),
            $pdo,
            'https://ledger.example.pk',
            72,
        );

        $adminId = $this->makeUser('faisal@rehmanbuilders.pk');

        $pdo->prepare('INSERT INTO organizations (name, slug, created_by) VALUES (?, ?, ?)')
            ->execute(['Rehman Builders', 'rehman-builders', $adminId]);
        $orgId = (int) $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, 'admin')")
            ->execute([$orgId, $adminId]);

        $this->admin = new Membership($orgId, $adminId, Role::Admin);
    }

    /* ------------------------------------------------- one-time password path */

    public function testCreatingAMemberReturnsAPasswordThatWorks(): void
    {
        $result = $this->addUsman('accountant');

        $password = $result['one_time_password'];
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $password);

        $user = $this->users->findByEmail('usman@rehmanbuilders.pk');
        self::assertNotNull($user);
        self::assertTrue(password_verify($password, (string) $user['password_hash']));
        self::assertSame(1, (int) $user['must_change_password']);
    }

    /** Somebody has to read this down a phone line. */
    public function testTheOneTimePasswordAvoidsCharactersThatLookAlike(): void
    {
        for ($attempt = 0; $attempt < 200; ++$attempt) {
            self::assertDoesNotMatchRegularExpression(
                '/[IL1O0]/',
                MemberService::generateOneTimePassword(),
            );
        }
    }

    public function testTheOneTimePasswordIsNeverStoredInClear(): void
    {
        $result = $this->addUsman();

        $stored = self::pdo()->query('SELECT password_hash FROM users')->fetchAll(PDO::FETCH_COLUMN);

        self::assertNotContains($result['one_time_password'], $stored);
        foreach ($stored as $hash) {
            self::assertStringStartsWith('$argon2id$', $hash);
        }
    }

    public function testAddingSomeoneWhoAlreadyHasAnAccountIssuesNoPassword(): void
    {
        $this->users->create('Zara Khan', 'zara@rehmanbuilders.pk', 'her-own-password');

        $result = $this->members->create($this->admin, 'Zara Khan', 'zara@rehmanbuilders.pk', 'viewer', '127.0.0.1');

        self::assertNull($result['one_time_password'], 'An existing account keeps the password it has.');
        $count = self::pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'zara@rehmanbuilders.pk'");
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testCreatingWithAnAdminTypedPasswordStoresItAndReturnsNoOneTimePassword(): void
    {
        $result = $this->members->create(
            $this->admin,
            'Usman Khan',
            self::PROVISIONED,
            'viewer',
            '127.0.0.1',
            'dictated-by-admin',
        );

        self::assertNull($result['one_time_password']);
        self::assertTrue($result['account_created']);

        $user = $this->users->findByEmail(self::PROVISIONED);
        self::assertTrue(password_verify('dictated-by-admin', (string) $user['password_hash']));
        self::assertSame(1, (int) $user['must_change_password'], 'The admin knows it, so it is still temporary.');
    }

    public function testATypedPasswordForAnExistingAccountIsRejected(): void
    {
        $this->users->create('Zara Khan', 'zara@rehmanbuilders.pk', 'her-own-password');

        $this->expectException(ValidationException::class);

        $this->members->create(
            $this->admin,
            'Zara Khan',
            'zara@rehmanbuilders.pk',
            'viewer',
            '127.0.0.1',
            'attempted-override',
        );
    }

    public function testAddingAnExistingMemberTwiceIsAConflict(): void
    {
        $this->addUsman();

        try {
            $this->addUsman();
            self::fail('Expected a conflict.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
        }
    }

    public function testAnAccountantCannotProvisionAnybody(): void
    {
        $accountant = new Membership($this->admin->orgId, $this->admin->userId, Role::Accountant);

        $this->expectException(HttpException::class);

        $this->members->create($accountant, 'Usman Khan', 'usman@rehmanbuilders.pk', 'viewer', '127.0.0.1');
    }

    /* ------------------------------------------------------------ invite path */

    public function testAnInviteLinkCarriesATokenAndStoresOnlyItsDigest(): void
    {
        $invite = $this->inviteNadia();

        self::assertStringStartsWith('https://ledger.example.pk/join/', $invite['signup_url']);

        $token = substr($invite['signup_url'], strrpos($invite['signup_url'], '/') + 1);
        $stored = self::pdo()->query('SELECT token_hash FROM invites')->fetchColumn();

        self::assertSame(64, strlen($token));
        self::assertNotSame($token, $stored);
        self::assertSame(hash('sha256', $token), $stored);
    }

    public function testThePreviewNamesTheOrganizationAndRoleBeforeCommitting(): void
    {
        $token = $this->tokenFor($this->inviteNadia('accountant'));

        $preview = $this->members->previewInvite($token);

        self::assertSame('Rehman Builders', $preview['organization']['name']);
        self::assertSame('accountant', $preview['role']);
        self::assertSame('Sana Rehman', $preview['invited_by']);
        self::assertFalse($preview['account_exists']);
    }

    public function testAcceptingCreatesTheAccountAndTheMembership(): void
    {
        $token = $this->tokenFor($this->inviteNadia());

        $result = $this->members->acceptInvite($token, 'Nadia Shafiq', 'correct-horse-battery', '127.0.0.1');

        self::assertTrue($result['account_created']);

        $user = $this->users->findByEmail('nadia@rehmanbuilders.pk');
        self::assertNotNull($user);
        self::assertSame(0, (int) $user['must_change_password'], 'They chose it, so nothing to force.');
        self::assertSame(Role::Viewer, $this->memberships->find($this->admin->orgId, (int) $user['id'])?->role);
    }

    public function testAnAcceptedLinkCannotBeUsedAgain(): void
    {
        $token = $this->tokenFor($this->inviteNadia());
        $this->members->acceptInvite($token, 'Nadia Shafiq', 'correct-horse-battery', '127.0.0.1');

        try {
            $this->members->acceptInvite($token, 'Impostor', 'another-password-here', '203.0.113.9');
            self::fail('Expected the spent link to be rejected.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }
    }

    public function testAcceptingWithoutANameOrPasswordIsAValidationFailure(): void
    {
        $token = $this->tokenFor($this->inviteNadia());

        $this->expectException(ValidationException::class);

        $this->members->acceptInvite($token, null, null, '127.0.0.1');
    }

    public function testAnExpiredLinkIsIndistinguishableFromAnUnknownOne(): void
    {
        $token = $this->tokenFor($this->inviteNadia());
        self::pdo()->exec('UPDATE invites SET expires_at = NOW() - INTERVAL 1 HOUR');

        $expired = $this->rejectionFor($token);
        $unknown = $this->rejectionFor(bin2hex(random_bytes(32)));

        self::assertSame($expired, $unknown);
    }

    public function testAWithdrawnLinkStopsWorking(): void
    {
        $invite = $this->inviteNadia();
        $this->members->revokeInvite($this->admin, $invite['id'], '127.0.0.1');

        $this->expectException(HttpException::class);

        $this->members->previewInvite($this->tokenFor($invite));
    }

    public function testDecliningBurnsTheLink(): void
    {
        $token = $this->tokenFor($this->inviteNadia());

        $this->members->declineInvite($token, '127.0.0.1');

        $this->expectException(HttpException::class);
        $this->members->acceptInvite($token, 'Nadia', 'correct-horse-battery', '127.0.0.1');
    }

    public function testReinvitingSupersedesTheOutstandingLink(): void
    {
        $first = $this->tokenFor($this->inviteNadia());
        $second = $this->tokenFor($this->inviteNadia('admin'));

        self::assertSame('admin', $this->members->previewInvite($second)['role']);

        $this->expectException(HttpException::class);
        $this->members->previewInvite($first);
    }

    /** Ownership is transferred deliberately in org settings, never handed out by a link. */
    public function testOwnerIsNotAnInvitableRole(): void
    {
        $this->expectException(\ValueError::class);

        $this->members->invite($this->admin, 'nadia@rehmanbuilders.pk', 'superuser', '127.0.0.1');
    }

    public function testPendingInvitesAreListedAlongsideMembers(): void
    {
        $this->inviteNadia();

        $listing = $this->members->list($this->admin);

        self::assertCount(1, $listing['members']);
        self::assertCount(1, $listing['pending_invites']);
        self::assertSame('nadia@rehmanbuilders.pk', $listing['pending_invites'][0]['email']);
    }

    /** A viewer may see who is on the team, but not the outstanding invitations. */
    public function testAViewerSeesMembersButNotPendingInvites(): void
    {
        $this->inviteNadia();

        $viewer = new Membership($this->admin->orgId, $this->admin->userId, Role::Viewer);
        $listing = $this->members->list($viewer);

        self::assertCount(1, $listing['members']);
        self::assertSame([], $listing['pending_invites']);
    }

    /* ------------------------------------------------------------- reset path */

    public function testResettingGeneratesAPasswordThatWorksAndForcesAChange(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];

        $result = $this->members->resetPassword($this->admin, $targetId, null, '127.0.0.1');

        $password = (string) $result['one_time_password'];
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $password);

        $user = $this->users->findByEmail(self::PROVISIONED);
        self::assertTrue(password_verify($password, (string) $user['password_hash']));
        self::assertSame(1, (int) $user['must_change_password']);
    }

    public function testResettingWithATypedPasswordStoresItAndReturnsNothing(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];

        $result = $this->members->resetPassword($this->admin, $targetId, 'dictated-by-admin', '127.0.0.1');

        self::assertNull($result['one_time_password'], 'The admin typed it, so there is nothing to reveal.');

        $user = $this->users->findByEmail(self::PROVISIONED);
        self::assertTrue(password_verify('dictated-by-admin', (string) $user['password_hash']));
        self::assertSame(1, (int) $user['must_change_password'], 'The admin knows it, so it is still temporary.');
    }

    public function testResettingRevokesEveryRefreshTokenTheMemberHeld(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];

        self::pdo()
            ->prepare(
                'INSERT INTO refresh_tokens (user_id, family_id, token_hash, expires_at)
                 VALUES (?, ?, ?, NOW() + INTERVAL 30 DAY)'
            )
            ->execute([$targetId, bin2hex(random_bytes(16)), hash('sha256', 'live-session')]);

        $this->members->resetPassword($this->admin, $targetId, null, '127.0.0.1');

        $alive = self::pdo()->prepare(
            'SELECT COUNT(*) FROM refresh_tokens WHERE user_id = ? AND revoked_at IS NULL'
        );
        $alive->execute([$targetId]);

        self::assertSame(0, (int) $alive->fetchColumn(), 'A reset that leaves sessions alive is not a reset.');
    }

    public function testNobodyResetsTheOwnersPassword(): void
    {
        $ownerId = $this->makeUser('owner@rehmanbuilders.pk');
        self::pdo()
            ->prepare("INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, 'owner')")
            ->execute([$this->admin->orgId, $ownerId]);

        try {
            $this->members->resetPassword($this->admin, $ownerId, null, '127.0.0.1');
            self::fail('Expected a 403.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testAnAccountantCannotResetAnybody(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];
        $accountant = new Membership($this->admin->orgId, $this->admin->userId, Role::Accountant);

        try {
            $this->members->resetPassword($accountant, $targetId, null, '127.0.0.1');
            self::fail('Expected a 403.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
        }
    }

    public function testResettingSomeoneOutsideTheOrganizationIsNotFound(): void
    {
        $strangerId = $this->makeUser('stranger@elsewhere.pk');

        try {
            $this->members->resetPassword($this->admin, $strangerId, null, '127.0.0.1');
            self::fail('Expected a 404.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }
    }

    public function testTheResetIsLogged(): void
    {
        $targetId = (int) $this->addUsman()['user']['id'];

        $this->members->resetPassword($this->admin, $targetId, null, '127.0.0.1');

        $logged = self::pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'member.password_reset'"
        );
        self::assertSame(1, (int) $logged->fetchColumn());
    }

    /** @return array<string, mixed> */
    private function inviteNadia(string $role = 'viewer'): array
    {
        return $this->members->invite($this->admin, self::INVITEE, $role, '127.0.0.1');
    }

    /** @return array<string, mixed> */
    private function addUsman(string $role = 'viewer'): array
    {
        return $this->members->create($this->admin, 'Usman Khan', self::PROVISIONED, $role, '127.0.0.1');
    }

    /** @param array<string, mixed> $invite */
    private function tokenFor(array $invite): string
    {
        $url = (string) $invite['signup_url'];

        return substr($url, strrpos($url, '/') + 1);
    }

    private function rejectionFor(string $token): string
    {
        try {
            $this->members->previewInvite($token);
        } catch (HttpException $e) {
            return $e->status() . '/' . $e->errorCode() . '/' . $e->getMessage();
        }

        self::fail('Expected the token to be rejected.');
    }
}
