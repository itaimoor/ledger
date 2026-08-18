<?php

declare(strict_types=1);

namespace Ledger\Repositories;

use Ledger\Domain\Role;
use Ledger\Security\Membership;
use PDO;

final class MembershipRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * The tenant gate. Returns null when the caller is not a member, when the
     * organization does not exist, and when it has been deleted — the three cases are
     * indistinguishable to the caller by design.
     */
    public function find(int $orgId, int $userId): ?Membership
    {
        $statement = $this->pdo->prepare(
            'SELECT m.role
             FROM organization_members m
             JOIN organizations o ON o.id = m.org_id
             WHERE m.org_id = ? AND m.user_id = ? AND o.deleted_at IS NULL'
        );
        $statement->execute([$orgId, $userId]);

        $role = $statement->fetchColumn();

        return $role === false ? null : new Membership($orgId, $userId, Role::from((string) $role));
    }

    /** @return list<array<string, mixed>> */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.name, o.slug, m.role, m.joined_at
             FROM organization_members m
             JOIN organizations o ON o.id = m.org_id
             WHERE m.user_id = ? AND o.deleted_at IS NULL
             ORDER BY o.name'
        );
        $statement->execute([$userId]);

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listForOrg(int $orgId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.name, u.email, u.status, u.last_seen_at, m.role, m.joined_at
             FROM organization_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.org_id = ?
             ORDER BY FIELD(m.role, 'owner', 'admin', 'accountant', 'viewer'), u.name"
        );
        $statement->execute([$orgId]);

        return $statement->fetchAll();
    }

    public function add(int $orgId, int $userId, string $role): void
    {
        $this->pdo
            ->prepare('INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$orgId, $userId, $role]);
    }

    public function updateRole(int $orgId, int $userId, string $role): void
    {
        $this->pdo
            ->prepare('UPDATE organization_members SET role = ? WHERE org_id = ? AND user_id = ?')
            ->execute([$role, $orgId, $userId]);
    }

    public function remove(int $orgId, int $userId): void
    {
        $this->pdo
            ->prepare('DELETE FROM organization_members WHERE org_id = ? AND user_id = ?')
            ->execute([$orgId, $userId]);
    }
}
