<?php

declare(strict_types=1);

namespace Ledger\Security;

use Ledger\Domain\ProjectStatus;

/**
 * A project the caller is allowed to see, together with their standing in its
 * organization.
 *
 * Entry routes are addressed as /projects/{id}/… with no organization in the path, so the
 * tenant is derived from the project itself in a single query that joins through
 * organization_members. No row, no context, 404 — and the caller cannot tell a project in
 * someone else's organization from one that was never created.
 */
final class ProjectContext
{
    public function __construct(
        public readonly Membership $membership,
        public readonly int $projectId,
        public readonly string $projectName,
        public readonly ProjectStatus $status,
    ) {
    }
}
