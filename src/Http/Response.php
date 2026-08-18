<?php

declare(strict_types=1);

namespace Ledger\Http;

use stdClass;

final class Response
{
    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, string>     $headers
     */
    private function __construct(
        private readonly int $status,
        private readonly ?array $payload,
        private array $headers = [],
    ) {
    }

    /** @param array<string, mixed> $meta */
    public static function ok(mixed $data, array $meta = []): self
    {
        return new self(200, ['data' => $data, 'meta' => $meta]);
    }

    /** @param array<string, mixed> $meta */
    public static function created(mixed $data, array $meta = []): self
    {
        return new self(201, ['data' => $data, 'meta' => $meta]);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public static function failure(
        int $status,
        string $code,
        string $message,
        array $fields = [],
        array $headers = [],
    ): self {
        return new self(
            $status,
            ['error' => ['code' => $code, 'message' => $message, 'fields' => $fields]],
            $headers,
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Exposed so tests can assert on the envelope without capturing output. */
    public function body(): string
    {
        if ($this->payload === null) {
            return '';
        }

        $payload = $this->payload;

        // `meta` and `fields` are maps. An empty PHP array encodes as `[]`, which breaks a
        // client expecting an object on exactly the responses that carry no extras. This is
        // deliberately not recursive: an empty *list* inside `data` must stay `[]`.
        if (isset($payload['meta']) && $payload['meta'] === []) {
            $payload['meta'] = new stdClass();
        }

        if (isset($payload['error']['fields']) && $payload['error']['fields'] === []) {
            $payload['error']['fields'] = new stdClass();
        }

        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public function send(): void
    {
        http_response_code($this->status);

        // Balances and entry lists must never sit in a shared cache.
        header('Cache-Control: no-store');

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->payload === null) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo $this->body();
    }
}
