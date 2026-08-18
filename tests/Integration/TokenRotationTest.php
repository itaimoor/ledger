<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Auth\Jwt;
use Ledger\Auth\TokenService;
use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\RefreshTokenRepository;
use PDO;

final class TokenRotationTest extends DatabaseTestCase
{
    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = self::pdo();

        $this->tokens = new TokenService(
            new Jwt('MJ1qTQ0bYQ1BDkPfaWmnvyRs0mNTUXGvIRFYfLcCXsE=', 'ledger'),
            new RefreshTokenRepository($pdo),
            new ActivityLogRepository($pdo),
            $pdo,
            900,
            30,
        );
    }

    public function testRotationIssuesANewTokenAndRetiresTheOld(): void
    {
        $userId = $this->makeUser();
        $first = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phpunit');

        $second = $this->tokens->rotate($first['refresh_token'], '127.0.0.1', 'phpunit');

        self::assertNotSame($first['refresh_token'], $second['refresh_token']);
        self::assertNotNull($this->tokenRow($first['refresh_token'])['used_at']);
        self::assertNull($this->tokenRow($second['refresh_token'])['used_at']);
    }

    public function testSuccessorsStayInTheSameFamily(): void
    {
        $userId = $this->makeUser();
        $first = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phpunit');
        $second = $this->tokens->rotate($first['refresh_token'], '127.0.0.1', 'phpunit');
        $third = $this->tokens->rotate($second['refresh_token'], '127.0.0.1', 'phpunit');

        self::assertSame(
            $this->tokenRow($first['refresh_token'])['family_id'],
            $this->tokenRow($third['refresh_token'])['family_id'],
        );
    }

    public function testASeparateLoginStartsASeparateFamily(): void
    {
        $userId = $this->makeUser();
        $phone = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phone');
        $laptop = $this->tokens->issuePair($userId, null, '127.0.0.1', 'laptop');

        self::assertNotSame(
            $this->tokenRow($phone['refresh_token'])['family_id'],
            $this->tokenRow($laptop['refresh_token'])['family_id'],
        );
    }

    /** The whole point: a captured token, replayed after the victim has refreshed. */
    public function testReplayingASpentTokenIsRejected(): void
    {
        $userId = $this->makeUser();
        $stolen = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phpunit');
        $this->tokens->rotate($stolen['refresh_token'], '127.0.0.1', 'phpunit');

        try {
            $this->tokens->rotate($stolen['refresh_token'], '10.0.0.9', 'attacker');
            self::fail('Expected the replay to be rejected.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status());
        }
    }

    public function testReplayRevokesTheEntireFamilyIncludingTheLiveToken(): void
    {
        $userId = $this->makeUser();
        $stolen = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phpunit');
        $live = $this->tokens->rotate($stolen['refresh_token'], '127.0.0.1', 'phpunit');

        try {
            $this->tokens->rotate($stolen['refresh_token'], '10.0.0.9', 'attacker');
        } catch (HttpException) {
            // expected
        }

        self::assertNotNull(
            $this->tokenRow($live['refresh_token'])['revoked_at'],
            'The victim\'s still-unused token must be revoked too.',
        );

        $this->expectException(HttpException::class);
        $this->tokens->rotate($live['refresh_token'], '127.0.0.1', 'phpunit');
    }

    public function testReplayIsRecordedInTheActivityLog(): void
    {
        $userId = $this->makeUser();
        $stolen = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phpunit');
        $this->tokens->rotate($stolen['refresh_token'], '127.0.0.1', 'phpunit');

        try {
            $this->tokens->rotate($stolen['refresh_token'], '10.0.0.9', 'attacker');
        } catch (HttpException) {
            // expected
        }

        $row = self::pdo()
            ->query("SELECT user_id, ip, after_json FROM activity_log WHERE action = 'auth.refresh_reuse_detected'")
            ->fetch();

        self::assertNotFalse($row, 'Reuse detection must leave an audit trail.');
        self::assertSame($userId, (int) $row['user_id']);
        self::assertSame('10.0.0.9', $row['ip']);
        self::assertStringContainsString('tokens_revoked', (string) $row['after_json']);
    }

    public function testAnUnknownTokenIsRejected(): void
    {
        $this->expectException(HttpException::class);

        $this->tokens->rotate(bin2hex(random_bytes(32)), '127.0.0.1', 'phpunit');
    }

    public function testAnExpiredTokenIsRejectedAndNotRotated(): void
    {
        $userId = $this->makeUser();
        $pair = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phpunit');

        self::pdo()
            ->prepare('UPDATE refresh_tokens SET expires_at = NOW() - INTERVAL 1 DAY WHERE token_hash = ?')
            ->execute([hash('sha256', $pair['refresh_token'])]);

        try {
            $this->tokens->rotate($pair['refresh_token'], '127.0.0.1', 'phpunit');
            self::fail('Expected the expired token to be rejected.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status());
        }

        self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM refresh_tokens')->fetchColumn());
    }

    public function testTheRawRefreshTokenIsNeverStored(): void
    {
        $userId = $this->makeUser();
        $pair = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phpunit');

        $stored = self::pdo()->query('SELECT token_hash FROM refresh_tokens')->fetchColumn();

        self::assertNotSame($pair['refresh_token'], $stored);
        self::assertSame(hash('sha256', $pair['refresh_token']), $stored);
    }

    public function testLogoutRevokesTheWholeFamily(): void
    {
        $userId = $this->makeUser();
        $first = $this->tokens->issuePair($userId, null, '127.0.0.1', 'phpunit');
        $second = $this->tokens->rotate($first['refresh_token'], '127.0.0.1', 'phpunit');

        $this->tokens->revokeFamilyOf($second['refresh_token']);

        $this->expectException(HttpException::class);
        $this->tokens->rotate($second['refresh_token'], '127.0.0.1', 'phpunit');
    }

    /** @return array<string, mixed> */
    private function tokenRow(string $refreshToken): array
    {
        $statement = self::pdo()->prepare(
            'SELECT family_id, used_at, revoked_at FROM refresh_tokens WHERE token_hash = ?'
        );
        $statement->execute([hash('sha256', $refreshToken)]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($row, 'Expected the token to exist.');

        return $row;
    }
}
