<?php

declare(strict_types=1);

namespace Ledger\Exceptions;

final class ValidationException extends HttpException
{
    /** @param array<string, string> $invalidFields field name => message shown to the user */
    public function __construct(
        private readonly array $invalidFields,
        string $message = 'The request contains invalid data.',
    ) {
        parent::__construct(422, 'validation_failed', $message);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->invalidFields;
    }
}
