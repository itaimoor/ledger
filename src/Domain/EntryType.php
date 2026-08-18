<?php

declare(strict_types=1);

namespace Ledger\Domain;

enum EntryType: string
{
    case In = 'in';
    case Out = 'out';

    /** A reconciling entry is always the opposite direction of the one it corrects. */
    public function opposite(): self
    {
        return $this === self::In ? self::Out : self::In;
    }

    /** The design requires a category on money out, and leaves it optional on money in. */
    public function requiresCategory(): bool
    {
        return $this === self::Out;
    }
}
