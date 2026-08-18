<?php

declare(strict_types=1);

namespace Ledger\Http;

use Ledger\Exceptions\HttpException;

final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    /** @param callable(Request, array<string, string>): Response $handler */
    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /** @param callable(Request, array<string, string>): Response $handler */
    private function add(string $method, string $pattern, callable $handler): void
    {
        // ponytail: patterns are developer-authored literals containing only letters,
        // digits, `/`, `-`, `_` and `{name}` placeholders, so they go into the regex
        // unescaped. They never come from a request.
        $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', $pattern);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            /** @var array<string, string> $params */
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return ($route['handler'])($request, $params);
        }

        if ($allowedMethods !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowedMethods)));
        }

        throw HttpException::notFound('No such endpoint.');
    }
}
