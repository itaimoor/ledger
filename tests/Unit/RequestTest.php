<?php

declare(strict_types=1);

namespace Ledger\Tests\Unit;

use Ledger\Exceptions\HttpException;
use Ledger\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private const JSON = ['Content-Type' => 'application/json'];

    public function testParsesAJsonObjectBody(): void
    {
        $request = new Request('POST', '/x', headers: self::JSON, rawBody: '{"amount_paisa":18500000}');

        self::assertSame(['amount_paisa' => 18500000], $request->body());
    }

    public function testEmptyBodyIsAnEmptyArrayAndNeedsNoContentType(): void
    {
        self::assertSame([], (new Request('POST', '/x'))->body());
    }

    /** `{}` decodes to the same PHP value as `[]`, and it must not be caught by the list check. */
    public function testAnEmptyJsonObjectIsAValidEmptyBody(): void
    {
        $request = new Request('POST', '/x', headers: self::JSON, rawBody: '{}');

        self::assertSame([], $request->body());
    }

    public function testRejectsABodySentAsAnythingButJson(): void
    {
        $request = new Request(
            'POST',
            '/x',
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
            rawBody: 'amount_paisa=18500000',
        );

        try {
            $request->body();
            self::fail('Expected an HttpException.');
        } catch (HttpException $e) {
            self::assertSame(415, $e->status());
        }
    }

    public function testRejectsMalformedJson(): void
    {
        $request = new Request('POST', '/x', headers: self::JSON, rawBody: '{"amount_paisa":');

        try {
            $request->body();
            self::fail('Expected an HttpException.');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status());
        }
    }

    /** A top-level array would make every `$body['field']` lookup silently null. */
    public function testRejectsAJsonArrayAtTheTopLevel(): void
    {
        $request = new Request('POST', '/x', headers: self::JSON, rawBody: '[{"amount_paisa":1}]');

        try {
            $request->body();
            self::fail('Expected an HttpException.');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status());
        }
    }

    public function testContentTypeWithCharsetIsAccepted(): void
    {
        $request = new Request(
            'POST',
            '/x',
            headers: ['CONTENT-TYPE' => 'application/json; charset=utf-8'],
            rawBody: '{"ok":true}',
        );

        self::assertSame(['ok' => true], $request->body());
    }

    public function testExtractsABearerToken(): void
    {
        $request = new Request('GET', '/x', headers: ['Authorization' => 'Bearer abc.def.ghi']);

        self::assertSame('abc.def.ghi', $request->bearerToken());
    }

    public function testIgnoresANonBearerAuthorizationScheme(): void
    {
        $request = new Request('GET', '/x', headers: ['Authorization' => 'Basic c2FuYTpwdw==']);

        self::assertNull($request->bearerToken());
    }

    public function testArrayValuedQueryParametersAreRejectedRatherThanCoerced(): void
    {
        $request = new Request('GET', '/x', query: ['cursor' => ['a', 'b'], 'limit' => '50']);

        self::assertNull($request->query('cursor'));
        self::assertSame('50', $request->query('limit'));
    }

    public function testMethodIsUppercasedAndTrailingSlashIsTrimmed(): void
    {
        $request = new Request('post', '/api/v1/organizations/');

        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/organizations', $request->path);
    }

    public function testRootPathKeepsItsSlash(): void
    {
        self::assertSame('/', (new Request('GET', '/'))->path);
    }
}
