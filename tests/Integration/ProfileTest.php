<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Auth\AuthenticatedUser;
use Ledger\Auth\Jwt;
use Ledger\Auth\RateLimiter;
use Ledger\Auth\TokenService;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Repositories\OrganizationRepository;
use Ledger\Repositories\RefreshTokenRepository;
use Ledger\Repositories\UserRepository;
use Ledger\Security\Policy;
use Ledger\Services\AuthService;
use Ledger\Services\OrganizationService;

/** Self-service profile changes: a person renames themselves, nobody else. */
final class ProfileTest extends DatabaseTestCase
{
    private AuthService $auth;

    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::pdo();
        $activity = new ActivityLogRepository($pdo);
        $this->users = new UserRepository($pdo);

        $jwt = new Jwt('MJ1qTQ0bYQ1BDkPfaWmnvyRs0mNTUXGvIRFYfLcCXsE=', 'ledger');

        $this->auth = new AuthService(
            $this->users,
            new TokenService($jwt, new RefreshTokenRepository($pdo), $activity, $pdo, 900, 30),
            new RateLimiter($pdo, 300),
            $activity,
            new OrganizationService(
                new OrganizationRepository($pdo),
                new MembershipRepository($pdo),
                new CategoryRepository($pdo),
                $activity,
                new Policy($activity),
                $pdo,
            ),
            $pdo,
            30,
            5,
            5,
        );
    }

    private function caller(): AuthenticatedUser
    {
        $id = $this->makeUser();

        return new AuthenticatedUser($id, 'Sana Rehman', 'sana@rehmanbuilders.pk', false);
    }

    public function testRenamingPersistsAndReturnsTheNewName(): void
    {
        $caller = $this->caller();

        $result = $this->auth->updateName($caller, 'Sana R. Chaudhry', '127.0.0.1');

        self::assertSame('Sana R. Chaudhry', $result['name']);
        self::assertSame(
            'Sana R. Chaudhry',
            (string) $this->users->findByEmail('sana@rehmanbuilders.pk')['name'],
        );
    }

    public function testRenamingIsLoggedWithBeforeAndAfter(): void
    {
        $this->auth->updateName($this->caller(), 'Sana R. Chaudhry', '127.0.0.1');

        $row = self::pdo()
            ->query("SELECT before_json, after_json FROM activity_log WHERE action = 'user.renamed'")
            ->fetch();

        self::assertNotFalse($row, 'Expected the rename to be logged.');
        self::assertStringContainsString('Sana Rehman', (string) $row['before_json']);
        self::assertStringContainsString('Sana R. Chaudhry', (string) $row['after_json']);
    }
}
