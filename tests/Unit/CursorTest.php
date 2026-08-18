<?php

declare(strict_types=1);

namespace Ledger\Tests\Unit;

use Ledger\Exceptions\ValidationException;
use Ledger\Support\Cursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function testRoundTripsADatePosition(): void
    {
        self::assertSame(
            ['value' => '2026-08-17', 'id' => 1482],
            Cursor::decode(Cursor::encode('2026-08-17', 1482)),
        );
    }

    /** The activity log sorts on a datetime, so the same codec has to carry one. */
    public function testRoundTripsADatetimePosition(): void
    {
        self::assertSame(
            ['value' => '2026-08-17 09:14:03', 'id' => 77],
            Cursor::decode(Cursor::encode('2026-08-17 09:14:03', 77)),
        );
    }

    public function testRejectsATruncatedDatetime(): void
    {
        $this->expectException(ValidationException::class);

        Cursor::decode(self::raw('2026-08-17 09:14|5'));
    }

    public function testNoCursorMeansTheFirstPage(): void
    {
        self::assertNull(Cursor::decode(null));
        self::assertNull(Cursor::decode(''));
    }

    public function testIsUrlSafe(): void
    {
        $cursor = Cursor::encode('2026-08-17', 1482);

        self::assertSame($cursor, rawurlencode($cursor));
    }

    #[DataProvider('rubbish')]
    public function testRejectsAnythingThatIsNotACursor(string $cursor): void
    {
        $this->expectException(ValidationException::class);

        Cursor::decode($cursor);
    }

    /** @return iterable<string, array{string}> */
    public static function rubbish(): iterable
    {
        yield 'not base64' => ['!!!!'];
        yield 'no separator' => [self::raw('2026-08-17')];
        yield 'id is not a number' => [self::raw('2026-08-17|abc')];
        yield 'date is not a date' => [self::raw('yesterday|5')];
        yield 'impossible date' => [self::raw('2026-02-30|5')];
        yield 'extra field' => [self::raw('2026-08-17|5|admin')];
        yield 'sql smuggled in' => [self::raw("2026-08-17|1 OR 1=1")];
    }

    private static function raw(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}
