<?php

declare(strict_types=1);

namespace Ledger\Auth;

use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ActivityLogRepository;
use Ledger\Repositories\RefreshTokenRepository;
use PDO;
use Throwable;

/**
 * Issues access tokens and manages the rotating refresh-token family.
 *
 * One login starts one family. Each refresh consumes its token and issues a successor in
 * the same family. Presenting a token that has already been consumed means the token was
 * captured, so the entire family is revoked and both the attacker and the legitimate
 * holder are forced back to the login screen.
 */
final class TokenService
{
    public function __construct(
        private readonly Jwt $jwt,
        private readonly RefreshTokenRepository $tokens,
        private readonly ActivityLogRepository $activity,
        private readonly PDO $pdo,
        private readonly int $accessTtlSeconds,
        private readonly int $refreshTtlDays,
    ) {
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    public function issuePair(int $userId, ?string $familyId, ?string $ip, ?string $userAgent): array
    {
        // 256 bits of entropy, so a fast digest at rest is the right trade rather than a
        // password hash: there is nothing here for Argon2id to slow down guessing of.
        $refreshToken = bin2hex(random_bytes(32));

        $this->tokens->insert(
            $userId,
            $familyId ?? bin2hex(random_bytes(16)),
            hash('sha256', $refreshToken),
            date('Y-m-d H:i:s', time() + ($this->refreshTtlDays * 86400)),
            $ip,
            $userAgent,
        );

        return [
            'access_token' => $this->jwt->issue($userId, $this->accessTtlSeconds),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTtlSeconds,
            'refresh_token' => $refreshToken,
        ];
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     *
     * @throws HttpException 401 for an unknown, expired, revoked or already-used token,
     *                       always with the same message
     */
    public function rotate(string $presented, ?string $ip, ?string $userAgent): array
    {
        $row = $this->consume($presented, $ip);

        return $this->issuePair((int) $row['user_id'], (string) $row['family_id'], $ip, $userAgent);
    }

    public function revokeFamilyOf(string $presented): void
    {
        $row = $this->tokens->findByHashForUpdate(hash('sha256', $presented));

        if ($row !== null) {
            $this->tokens->revokeFamily((string) $row['family_id']);
        }
    }

    /**
     * Marks the presented token used, or blows up the family if it was already spent.
     *
     * @return array<string, mixed>
     */
    private function consume(string $presented, ?string $ip): array
    {
        $this->pdo->beginTransaction();

        try {
            $row = $this->tokens->findByHashForUpdate(hash('sha256', $presented));

            if ($row === null) {
                $this->pdo->commit();
                $this->activity->record(null, null, 'auth.refresh_unknown', ip: $ip);

                throw HttpException::unauthorized();
            }

            $userId = (int) $row['user_id'];
            $familyId = (string) $row['family_id'];
            $reused = $row['used_at'] !== null || $row['revoked_at'] !== null;

            if ($reused) {
                $revoked = $this->tokens->revokeFamily($familyId);
                // committed before throwing: the revocation must survive the rejection
                $this->pdo->commit();
                $this->activity->record(
                    null,
                    $userId,
                    'auth.refresh_reuse_detected',
                    'refresh_token_family',
                    null,
                    null,
                    ['family_id' => $familyId, 'tokens_revoked' => $revoked],
                    $ip,
                );

                throw HttpException::unauthorized();
            }

            if (strtotime((string) $row['expires_at']) <= time()) {
                $this->pdo->commit();
                $this->activity->record(null, $userId, 'auth.refresh_expired', ip: $ip);

                throw HttpException::unauthorized();
            }

            $this->tokens->markUsed((int) $row['id']);
            $this->pdo->commit();

            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
