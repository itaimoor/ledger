<?php

declare(strict_types=1);

namespace Ledger\Domain;

enum CategoryType: string
{
    case In = 'in';
    case Out = 'out';
    case Both = 'both';

    public function allows(EntryType $entryType): bool
    {
        return $this === self::Both || $this->value === $entryType->value;
    }
}
