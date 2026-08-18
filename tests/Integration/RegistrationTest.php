<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Auth\Jwt;
use Ledger\Auth\RateLimiter;
use Ledger\Auth\TokenService;
use Ledger\Exceptions\ValidationException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Repositories\OrganizationRepository;
use Ledger\Repositories\RefreshTokenRepository;
use Ledger\Repositories\UserRepository;
use Ledger\Security\Policy;
use Ledger\Services\AuthService;
use Ledger\Services\OrganizationService;

final class RegistrationTest extends DatabaseTestCase
{
    private AuthService $auth;

    private MembershipRepository $memberships;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::pdo();
        $activity = new ActivityLogRepository($pdo);
        $users = new UserRepository($pdo);
        $this->memberships = new MembershipRepository($pdo);

        $jwt = new Jwt('MJ1qTQ0bYQ1BDkPfaWmnvyRs0mNTUXGvIRFYfLcCXsE=', 'ledger');

        $this->auth = new AuthService(
            $users,
            new TokenService($jwt, new RefreshTokenRepository($pdo), $activity, $pdo, 900, 30),
            new RateLimiter($pdo, 300),
            $activity,
            new OrganizationService(
                new OrganizationRepository($pdo),
                $this->memberships,
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

    /** @return array<string, mixed> */
    private function register(
        string $email = 'sana@rehmanbuilders.pk',
        string $org = 'Rehman Builders (Pvt) Ltd',
    ): array {
        return $this->auth->register('Sana Rehman', $email, 'correct-horse-battery', $org, '127.0.0.1', 'phpunit');
    }

    public function testCreatesTheAccountOrganizationAndOwnerMembershipTogether(): void
    {
        $result = $this->register();

        $orgId = $result['organization']['id'];
        $userId = (int) self::pdo()->query('SELECT id FROM users')->fetchColumn();

        self::assertSame('rehman-builders-pvt-ltd', $result['organization']['slug']);
        self::assertSame('owner', $this->memberships->find($orgId, $userId)?->role->value);
        self::assertArrayHasKey('access_token', $result);
        self::assertArrayHasKey('refresh_token', $result);
        self::assertFalse($result['must_change_password']);
    }

    public function testSeedsTheDefaultCategories(): void
    {
        $this->register();

        $names = self::pdo()->query('SELECT name FROM categories ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(
            ['Labour', 'Material', 'Transport', 'Fuel', 'Rent', 'Misc', 'Client payment'],
            $names,
        );
    }

    public function testASecondOrganizationWithTheSameNameGetsADistinctSlug(): void
    {
        $this->register('sana@rehmanbuilders.pk');
        $second = $this->register('other@example.pk');

        self::assertSame('rehman-builders-pvt-ltd-2', $second['organization']['slug']);
    }

    public function testANameWithNoLatinCharactersStillProducesAUsableSlug(): void
    {
        $result = $this->register('urdu@example.pk', 'رحمان بلڈرز');

        self::assertNotSame('', $result['organization']['slug']);
        self::assertSame('org', $result['organization']['slug']);
    }

    public function testARepeatedEmailIsRejected(): void
    {
        $this->register();

        $this->expectException(ValidationException::class);

        $this->register();
    }

    public function testAFailedRegistrationLeavesNoOrphanAccount(): void
    {
        $this->register();

        try {
            $this->register();
        } catch (ValidationException) {
            // expected
        }

        self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
    }

    public function testTheNewOwnerCanImmediatelySignInWithTheirOwnPassword(): void
    {
        $this->register();

        $login = $this->auth->login('sana@rehmanbuilders.pk', 'correct-horse-battery', '127.0.0.1', 'phpunit');

        self::assertArrayHasKey('access_token', $login);
        self::assertFalse($login['must_change_password']);
    }
}
