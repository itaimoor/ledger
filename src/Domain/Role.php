<?php

declare(strict_types=1);

namespace Ledger\Domain;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Viewer = 'viewer';

    /** Ownership is transferred deliberately in org settings, never handed out by invite. */
    public function isInvitable(): bool
    {
        return $this !== self::Owner;
    }
}
