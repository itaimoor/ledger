<?php

declare(strict_types=1);

namespace Ledger\Http;

use JsonException;
use Ledger\Exceptions\HttpException;

final class Request
{
    public readonly string $method;

    public readonly string $path;

    /** @var array<string, string> */
    private readonly array $headers;

    /** @var array<string, mixed>|null */
    private ?array $parsedBody = null;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers header names in any case
     */
    public function __construct(
        string $method,
        string $path,
        private readonly array $query = [],
        array $headers = [],
        private readonly string $rawBody = '',
        public readonly string $ip = '0.0.0.0',
    ) {
        $this->method = strtoupper($method);
        // a trailing slash must not create a second, distinct route
        $this->path = $path === '/' ? '/' : rtrim($path, '/');
        $this->headers = array_change_key_case($headers);
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            is_string($path) ? rawurldecode($path) : '/',
            $_GET,
            self::headersFromGlobals(),
            (string) file_get_contents('php://input'),
            // REMOTE_ADDR only. X-Forwarded-For is client-controlled, and trusting it
            // would let anyone reset their own rate-limit bucket by varying the header.
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        );
    }

    /** @return array<string, string> */
    private static function headersFromGlobals(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        // mod_php hands the Authorization header to apache_request_headers() but does not
        // always place it in $_SERVER, which would make every authenticated call fail.
        if (!isset($headers['authorization']) && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $headers['authorization'] = (string) $value;
                }
            }
        }

        return $headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function userAgent(): ?string
    {
        $agent = $this->header('user-agent');

        return $agent === null ? null : mb_substr($agent, 0, 255);
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /** Array-valued query parameters are rejected rather than coerced. */
    public function query(string $key): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        if ($this->parsedBody !== null) {
            return $this->parsedBody;
        }

        if (trim($this->rawBody) === '') {
            return $this->parsedBody = [];
        }

        if (!str_starts_with(strtolower($this->header('content-type') ?? ''), 'application/json')) {
            throw HttpException::unsupportedMediaType();
        }

        try {
            $decoded = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw HttpException::badRequest('Request body is not valid JSON.');
        }

        // `{}` and `[]` both decode to the empty PHP array; an empty body is acceptable
        // either way, so only a non-empty list is rejected here.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw HttpException::badRequest('Request body must be a JSON object.');
        }

        return $this->parsedBody = $decoded;
    }
}
