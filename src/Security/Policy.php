<?php

declare(strict_types=1);

namespace Ledger\Security;

use Ledger\Domain\ProjectStatus;
use Ledger\Domain\Role;
use Ledger\Exceptions\HttpException;
use Ledger\Repositories\ActivityLogRepository;

/**
 * The only place a role is compared to anything.
 *
 * can() is pure: same inputs, same answer, no I/O. authorize() adds the audit record and
 * the 403. Controllers call authorize(); tests call can().
 *
 * The $subject argument is the thing being acted on, when its own state changes the
 * answer: a ProjectStatus for entry actions (an archived project is read-only for
 * everyone, including the owner), or the target member's Role for member actions.
 */
final class Policy
{
    public function __construct(private readonly ActivityLogRepository $activity)
    {
    }

    public function can(Membership $membership, Action $action, ProjectStatus|Role|null $subject = null): bool
    {
        $role = $membership->role;
        $manages = $role === Role::Owner || $role === Role::Admin;

        return match ($action) {
            // Viewer included: read-only means read.
            //
            // There is no separate export permission. Export is built in the browser from
            // rows the caller has already been served, so a rule for it would gate nothing
            // and only look like it did.
            Action::ViewOrganization => true,

            // Fails closed. Without a ProjectStatus the answer is no, so a call site that
            // forgets to pass the project cannot accidentally bypass the archive rule.
            Action::CreateEntry,
            Action::ReconcileEntry => $role !== Role::Viewer
                && $subject instanceof ProjectStatus
                && $subject->acceptsEntries(),

            // The book is append-only. A wrong entry is corrected by a reconciling entry.
            Action::EditEntry,
            Action::DeleteEntry => false,

            Action::CreateProject,
            Action::UpdateProject,
            Action::DeleteProject,
            Action::ManageCategories => $manages,

            // Nobody edits or removes the owner through member management, and nobody —
            // the owner included — resets the owner's password from here.
            Action::ManageMembers,
            Action::ResetMemberPassword => $manages && $subject !== Role::Owner,

            Action::ManageOrganization => $role === Role::Owner,
        };
    }

    /** @throws HttpException 403, after recording the denial */
    public function authorize(
        Membership $membership,
        Action $action,
        ProjectStatus|Role|null $subject = null,
        ?string $ip = null,
    ): void {
        if ($this->can($membership, $action, $subject)) {
            return;
        }

        $this->activity->record(
            $membership->orgId,
            $membership->userId,
            'permission.denied',
            after: [
                'action' => $action->value,
                'role' => $membership->role->value,
                'subject' => $subject?->value,
            ],
            ip: $ip,
        );

        throw HttpException::forbidden();
    }
}
