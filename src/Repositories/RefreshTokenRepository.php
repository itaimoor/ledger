<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use PDO;

final class RefreshTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(
        int $userId,
        string $familyId,
        string $tokenHash,
        string $expiresAt,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $this->pdo
            ->prepare(
                'INSERT INTO refresh_tokens (user_id, family_id, token_hash, expires_at, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )
            ->execute([$userId, $familyId, $tokenHash, $expiresAt, $ip, $userAgent]);
    }

    /**
     * Locks the row for the duration of the surrounding transaction. Without the lock,
     * two concurrent refreshes with the same token could both pass the reuse check.
     *
     * @return array<string, mixed>|null
     */
    public function findByHashForUpdate(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, family_id, expires_at, used_at, revoked_at
             FROM refresh_tokens WHERE token_hash = ? FOR UPDATE'
        );
        $statement->execute([$tokenHash]);

        return $statement->fetch() ?: null;
    }

    public function markUsed(int $id): void
    {
        $this->pdo->prepare('UPDATE refresh_tokens SET used_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /**
     * Every family the user holds, in one statement: used when an admin resets their
     * password, where any surviving session would belong to whoever knew the old one.
     *
     * @return int rows revoked
     */
    public function revokeAllForUser(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$userId]);

        return $statement->rowCount();
    }

    /** @return int rows revoked */
    public function revokeFamily(string $familyId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE family_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$familyId]);

        return $statement->rowCount();
    }
}
