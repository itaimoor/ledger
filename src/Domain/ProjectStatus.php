<?php

declare(strict_types=1);

namespace Ledger\Domain;

enum ProjectStatus: string
{
    /** Accepts new entries. Counts toward organization totals. */
    case Active = 'active';

    /** Still accepts entries. Hidden from the default Active filter. */
    case Completed = 'completed';

    /** Read-only for everyone, including admins. Reversible. */
    case Archived = 'archived';

    public function acceptsEntries(): bool
    {
        return $this !== self::Archived;
    }
}
