<?php

declare(strict_types=1);

namespace Ledger\Auth;

/**
 * The caller, as proven by a verified access token.
 *
 * Carries no organization or role. A user can belong to several organizations, so which
 * one a request concerns comes from the URL and is checked against organization_members
 * on every request. Baking a role into this object would let it go stale the moment an
 * admin changes it.
 */
final class AuthenticatedUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly bool $mustChangePassword,
    ) {
    }
}
