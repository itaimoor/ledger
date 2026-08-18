<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Auth\Jwt;
use Ledger\Auth\RateLimiter;
use Ledger\Auth\TokenService;
use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\CategoryRepository;
use Ledger\Repositories\MembershipRepository;
use Ledger\Repositories\OrganizationRepository;
use Ledger\Repositories\RefreshTokenRepository;
use Ledger\Repositories\UserRepository;
use Ledger\Security\Policy;
use Ledger\Services\AuthService;
use Ledger\Services\OrganizationService;

final class RateLimitTest extends DatabaseTestCase
{
    private RateLimiter $limiter;

    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::pdo();
        $activity = new ActivityLogRepository($pdo);
        $users = new UserRepository($pdo);
        $memberships = new MembershipRepository($pdo);

        $this->limiter = new RateLimiter($pdo, 300);

        $this->auth = new AuthService(
            $users,
            new TokenService(
                new Jwt('MJ1qTQ0bYQ1BDkPfaWmnvyRs0mNTUXGvIRFYfLcCXsE=', 'ledger'),
                new RefreshTokenRepository($pdo),
                $activity,
                $pdo,
                900,
                30,
            ),
            $this->limiter,
            $activity,
            new OrganizationService(
                new OrganizationRepository($pdo),
                $memberships,
                new CategoryRepository($pdo),
                $activity,
                new Policy($activity),
                $pdo,
            ),
            $pdo,
            10,
            3,
            2,
        );
    }

    public function testAllowsUpToTheLimitThenRefuses(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->limiter->hit('test:bucket', 5);
        }

        try {
            $this->limiter->hit('test:bucket', 5);
            self::fail('Expected the sixth hit to be refused.');
        } catch (HttpException $e) {
            self::assertSame(429, $e->status());
            self::assertArrayHasKey('Retry-After', $e->headers());
            self::assertGreaterThan(0, (int) $e->headers()['Retry-After']);
        }
    }

    public function testBucketsAreIndependent(): void
    {
        $this->expectNotToPerformAssertions();

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->limiter->hit('login:ip:203.0.113.1', 3);
            $this->limiter->hit('login:ip:203.0.113.2', 3);
        }
    }

    /** One address must not be grindable from many hosts, nor one host walk a list. */
    public function testLoginIsMeteredByEmailAsWellAsAddress(): void
    {
        $this->makeUser('sana@rehmanbuilders.pk');

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            try {
                $this->auth->login('sana@rehmanbuilders.pk', 'wrong', "198.51.100.{$attempt}", 'phpunit');
            } catch (HttpException $e) {
                self::assertSame(401, $e->status(), 'Should still be a credential failure.');
            }
        }

        // A fourth attempt from a fourth address: the per-email bucket is spent.
        try {
            $this->auth->login('sana@rehmanbuilders.pk', 'wrong', '198.51.100.4', 'phpunit');
            self::fail('Expected the per-email limit to bite.');
        } catch (HttpException $e) {
            self::assertSame(429, $e->status());
        }
    }

    public function testTheEmailBucketDoesNotStoreTheAddressInClear(): void
    {
        $this->limiter->hit(RateLimiter::loginByEmail('sana@rehmanbuilders.pk'), 10);

        $buckets = self::pdo()->query('SELECT bucket FROM rate_limits')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertCount(1, $buckets);
        self::assertStringNotContainsString('sana', $buckets[0]);
        self::assertStringStartsWith('login:email:', $buckets[0]);
    }

    public function testACorrectPasswordStillCountsTowardTheLimit(): void
    {
        $this->makeUser('sana@rehmanbuilders.pk', 'Password123!');

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->auth->login('sana@rehmanbuilders.pk', 'Password123!', '198.51.100.9', 'phpunit');
        }

        // Otherwise a valid credential would be an unlimited oracle for probing accounts.
        $this->expectException(HttpException::class);
        $this->auth->login('sana@rehmanbuilders.pk', 'Password123!', '198.51.100.9', 'phpunit');
    }

    public function testRegistrationIsMeteredPerAddress(): void
    {
        $this->auth->register('One', 'one@example.pk', 'correct-horse-battery', 'Org One', '203.0.113.7', null);
        $this->auth->register('Two', 'two@example.pk', 'correct-horse-battery', 'Org Two', '203.0.113.7', null);

        try {
            $this->auth->register('Three', 'three@example.pk', 'correct-horse-battery', 'Org 3', '203.0.113.7', null);
            self::fail('Expected the third signup from one address to be refused.');
        } catch (HttpException $e) {
            self::assertSame(429, $e->status());
        }

        self::assertSame(2, (int) self::pdo()->query('SELECT COUNT(*) FROM organizations')->fetchColumn());
    }

    public function testCountersAreScopedToTheirWindow(): void
    {
        $this->limiter->hit('test:window', 1);

        // Age the row into the previous window; the current one starts clean.
        self::pdo()->exec('UPDATE rate_limits SET window_start = window_start - 600');

        $this->expectNotToPerformAssertions();
        $this->limiter->hit('test:window', 1);
    }
}
