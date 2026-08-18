<?php

declare(strict_types=1);

namespace Ledger\Tests\Unit;

use Ledger\Exceptions\HttpException;
use Ledger\Exceptions\ValidationException;
use Ledger\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testSuccessEnvelopeAlwaysCarriesDataAndMeta(): void
    {
        $response = Response::ok(['id' => 1]);

        self::assertSame('{"data":{"id":1},"meta":{}}', $response->body());
    }

    public function testEmptyMetaEncodesAsAnObjectButAnEmptyListStaysAList(): void
    {
        $response = Response::ok(['entries' => [], 'next_cursor' => null]);

        self::assertSame('{"data":{"entries":[],"next_cursor":null},"meta":{}}', $response->body());
    }

    public function testErrorEnvelopeAlwaysCarriesCodeMessageAndFields(): void
    {
        $response = Response::failure(404, 'not_found', 'Resource not found.');

        self::assertSame(
            '{"error":{"code":"not_found","message":"Resource not found.","fields":{}}}',
            $response->body(),
        );
    }

    public function testValidationFailureRendersPerFieldMessages(): void
    {
        $e = new ValidationException(['amount_paisa' => 'Must be at least 1.']);
        $response = Response::failure($e->status(), $e->errorCode(), $e->getMessage(), $e->fields());

        self::assertSame(422, $response->status());
        self::assertSame(
            '{"error":{"code":"validation_failed","message":"The request contains invalid data.",'
            . '"fields":{"amount_paisa":"Must be at least 1."}}}',
            $response->body(),
        );
    }

    public function testNoContentHasAnEmptyBody(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
    }

    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        $response = Response::ok(['name' => 'Maj. (R) Tariq Aziz — DHA/6']);

        self::assertStringContainsString('Maj. (R) Tariq Aziz — DHA/6', $response->body());
    }

    public function testCrossTenantAccessIsNotFoundRatherThanForbidden(): void
    {
        self::assertSame(404, HttpException::notFound()->status());
        self::assertSame('not_found', HttpException::notFound()->errorCode());
    }
}
