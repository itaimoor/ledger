<?php

declare(strict_types=1);

namespace Ledger\Security;

use Ledger\Domain\Role;

/**
 * Proof that a user belongs to an organization, and in what capacity.
 *
 * Produced only by MembershipRepository, from a row that exists. A caller who is not a
 * member gets no Membership at all, and the endpoint answers 404 — never 403, which
 * would confirm the organization exists.
 *
 * Every org-scoped query takes its org_id from here, so the tenant boundary is decided
 * in one place rather than re-derived from the URL at each call site.
 */
final class Membership
{
    public function __construct(
        public readonly int $orgId,
        public readonly int $userId,
        public readonly Role $role,
    ) {
    }
}
