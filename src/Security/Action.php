<?php

declare(strict_types=1);

namespace Ledger\Security;

/**
 * Every permission the application has. One case per row of the capability table in the
 * design system, so the two can be compared line by line.
 */
enum Action: string
{
    case ViewOrganization = 'organization.view';
    case ManageOrganization = 'organization.manage';

    case CreateProject = 'project.create';
    case UpdateProject = 'project.update';
    case DeleteProject = 'project.delete';

    case ManageCategories = 'category.manage';
    case ManageMembers = 'member.manage';
    case ResetMemberPassword = 'member.password_reset';

    case CreateEntry = 'entry.create';
    case ReconcileEntry = 'entry.reconcile';

    /**
     * Denied to every role, permanently. Kept as real cases rather than left out so that
     * adding an edit or delete endpoint later means deleting an explicit `false` here,
     * which is a decision somebody has to make on purpose.
     */
    case EditEntry = 'entry.edit';
    case DeleteEntry = 'entry.delete';
}
