<?php

declare(strict_types=1);

namespace Ledger\Tests\Unit;

use Ledger\Exceptions\HttpException;
use Ledger\Http\Request;
use Ledger\Http\Response;
use Ledger\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path);
    }

    public function testMatchesAndExtractsNamedParameters(): void
    {
        $router = new Router();
        $router->get(
            '/api/v1/organizations/{org}/projects/{project}',
            fn (Request $r, array $p): Response => Response::ok($p),
        );

        $response = $router->dispatch($this->request('GET', '/api/v1/organizations/7/projects/42'));

        self::assertSame(200, $response->status());
        self::assertSame('{"data":{"org":"7","project":"42"},"meta":{}}', $response->body());
    }

    public function testParametersNeverSpanASlash(): void
    {
        $router = new Router();
        $router->get('/api/v1/projects/{id}', fn (): Response => Response::ok(null));

        $this->expectException(HttpException::class);

        $router->dispatch($this->request('GET', '/api/v1/projects/1/entries'));
    }

    public function testTrailingSlashHitsTheSameRoute(): void
    {
        $router = new Router();
        $router->get('/api/v1/organizations', fn (): Response => Response::ok([]));

        $response = $router->dispatch($this->request('GET', '/api/v1/organizations/'));

        self::assertSame(200, $response->status());
    }

    public function testKnownPathWithWrongMethodIs405AndListsAllowedMethods(): void
    {
        $router = new Router();
        $router->get('/api/v1/projects/{id}/entries', fn (): Response => Response::ok([]));
        $router->post('/api/v1/projects/{id}/entries', fn (): Response => Response::created(null));

        try {
            $router->dispatch($this->request('DELETE', '/api/v1/projects/1/entries'));
            self::fail('Expected an HttpException.');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
            self::assertSame(['Allow' => 'GET, POST'], $e->headers());
        }
    }

    public function testUnknownPathIs404(): void
    {
        $router = new Router();
        $router->get('/api/v1/organizations', fn (): Response => Response::ok([]));

        try {
            $router->dispatch($this->request('GET', '/api/v1/nope'));
            self::fail('Expected an HttpException.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
        }
    }
}
