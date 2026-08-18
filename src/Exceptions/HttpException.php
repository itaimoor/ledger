<?php

declare(strict_types=1);

namespace Ledger\Exceptions;

use RuntimeException;

/**
 * An error the client is allowed to see.
 *
 * Anything thrown as one of these is rendered verbatim into the error envelope by the
 * front controller. Anything else becomes a generic 500 with the detail kept in the log,
 * so an unexpected failure can never leak internals into a response.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        private readonly string $errorCode,
        string $message,
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return [];
    }

    public static function badRequest(string $message): self
    {
        return new self(400, 'bad_request', $message);
    }

    /**
     * Deliberately vague. Callers must not distinguish "no such user" from "wrong
     * password" from "expired token" — that difference is an account oracle.
     */
    public static function unauthorized(string $message = 'Authentication failed.'): self
    {
        return new self(401, 'unauthenticated', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): self
    {
        return new self(403, 'forbidden', $message);
    }

    /**
     * Also the correct answer for a resource that exists but belongs to another tenant.
     * Returning 403 there would confirm the resource is real.
     */
    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(404, 'not_found', $message);
    }

    /** @param list<string> $allowed */
    public static function methodNotAllowed(array $allowed): self
    {
        sort($allowed);

        return new self(
            405,
            'method_not_allowed',
            'That method is not allowed on this endpoint.',
            ['Allow' => implode(', ', $allowed)],
        );
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }

    public static function unsupportedMediaType(): self
    {
        return new self(415, 'unsupported_media_type', 'Request body must be application/json.');
    }

    public static function tooManyRequests(int $retryAfter): self
    {
        return new self(
            429,
            'rate_limited',
            'Too many requests. Try again shortly.',
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
