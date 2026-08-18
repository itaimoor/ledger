<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use PDO;

final class InviteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        int $orgId,
        string $email,
        string $role,
        string $tokenHash,
        int $invitedBy,
        string $expiresAt,
    ): int {
        $this->pdo
            ->prepare(
                'INSERT INTO invites (org_id, email, role, token_hash, invited_by, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )
            ->execute([$orgId, $email, $role, $tokenHash, $invitedBy, $expiresAt]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Looked up by token hash alone — the token is the only thing the recipient has, and
     * they are not signed in yet. Expiry and acceptance are checked by the caller so the
     * three failure modes stay distinguishable in the log but not in the response.
     *
     * @return array<string, mixed>|null
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.org_id, i.email, i.role, i.expires_at, i.accepted_at, i.revoked_at,
                    o.name AS organization_name, o.deleted_at AS organization_deleted_at,
                    u.name AS invited_by_name
             FROM invites i
             JOIN organizations o ON o.id = i.org_id
             JOIN users u ON u.id = i.invited_by
             WHERE i.token_hash = ?'
        );
        $statement->execute([$tokenHash]);

        return $statement->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listPending(int $orgId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.email, i.role, i.created_at, i.expires_at, u.name AS invited_by_name
             FROM invites i
             JOIN users u ON u.id = i.invited_by
             WHERE i.org_id = ?
               AND i.accepted_at IS NULL
               AND i.revoked_at IS NULL
               AND i.expires_at > NOW()
             ORDER BY i.created_at DESC'
        );
        $statement->execute([$orgId]);

        return $statement->fetchAll();
    }

    public function markAccepted(int $inviteId, int $userId): void
    {
        $this->pdo
            ->prepare('UPDATE invites SET accepted_at = NOW(), accepted_user_id = ? WHERE id = ?')
            ->execute([$userId, $inviteId]);
    }

    public function revoke(int $orgId, int $inviteId): void
    {
        $this->pdo
            ->prepare('UPDATE invites SET revoked_at = NOW() WHERE org_id = ? AND id = ? AND revoked_at IS NULL')
            ->execute([$orgId, $inviteId]);
    }

    public function revokeById(int $inviteId): void
    {
        $this->pdo
            ->prepare('UPDATE invites SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL')
            ->execute([$inviteId]);
    }

    /** Re-inviting supersedes any outstanding invite for the same address. */
    public function revokePendingFor(int $orgId, string $email): void
    {
        $this->pdo
            ->prepare(
                'UPDATE invites SET revoked_at = NOW()
                 WHERE org_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL'
            )
            ->execute([$orgId, $email]);
    }
}
