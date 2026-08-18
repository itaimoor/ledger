<?php

declare(strict_types=1);

namespace Ledger\Tests\Unit;

use Ledger\Exceptions\ValidationException;
use Ledger\Support\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testReturnsDeclaredValues(): void
    {
        $data = (new Validator([
            'type' => 'out',
            'amount_paisa' => 18500000,
            'entry_date' => '2026-08-17',
            'description' => '  Steel bars 40 ton  ',
        ]))
            ->enum('type', ['in', 'out'])
            ->int('amount_paisa', min: 1)
            ->date('entry_date')
            ->string('description', max: 500, required: false)
            ->validate();

        self::assertSame('out', $data['type']);
        self::assertSame(18500000, $data['amount_paisa']);
        self::assertSame('2026-08-17', $data['entry_date']);
        self::assertSame('Steel bars 40 ton', $data['description']);
    }

    public function testAnOmittedOptionalFieldIsAbsentFromTheResult(): void
    {
        $data = (new Validator(['name' => 'Labour']))
            ->string('name', max: 80)
            ->id('category_id', required: false)
            ->validate();

        self::assertArrayNotHasKey(
            'category_id',
            $data,
            'PATCH must be able to tell "leave it alone" from "clear it".',
        );
    }

    public function testAnExplicitNullIsKeptSoPatchCanClearAField(): void
    {
        $data = (new Validator(['client_name' => null]))
            ->string('client_name', max: 160, required: false)
            ->validate();

        self::assertArrayHasKey('client_name', $data);
        self::assertNull($data['client_name']);
    }

    public function testUnknownFieldIsRejectedRatherThanIgnored(): void
    {
        $validator = (new Validator(['name' => 'Labour', 'org_id' => 99, 'is_admin' => true]))
            ->string('name', max: 80);

        try {
            $validator->validate();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->status());
            self::assertSame(['org_id' => 'Unknown field.', 'is_admin' => 'Unknown field.'], $e->fields());
        }
    }

    public function testEveryFailingFieldIsReportedAtOnce(): void
    {
        $validator = (new Validator(['type' => 'sideways', 'amount_paisa' => 0]))
            ->enum('type', ['in', 'out'])
            ->int('amount_paisa', min: 1)
            ->date('entry_date');

        try {
            $validator->validate();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['type', 'amount_paisa', 'entry_date'], array_keys($e->fields()));
        }
    }

    /** Money must not arrive as a string, a float, or a boolean. */
    #[DataProvider('nonIntegerAmounts')]
    public function testAmountMustBeAnInteger(mixed $amount): void
    {
        $this->expectException(ValidationException::class);

        (new Validator(['amount_paisa' => $amount]))
            ->int('amount_paisa', min: 1)
            ->validate();
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonIntegerAmounts(): iterable
    {
        yield 'numeric string' => ['18500000'];
        yield 'float' => [185000.50];
        yield 'boolean' => [true];
        yield 'array' => [[18500000]];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsDatesThatAreNotRealIsoDates(string $date): void
    {
        $this->expectException(ValidationException::class);

        (new Validator(['entry_date' => $date]))->date('entry_date')->validate();
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDates(): iterable
    {
        yield 'day out of range' => ['2026-02-30'];
        yield 'month out of range' => ['2026-13-01'];
        yield 'wrong separator' => ['2026/08/17'];
        yield 'includes a time' => ['2026-08-17T09:00:00Z'];
        yield 'not a date at all' => ['yesterday'];
    }

    public function testAcceptsALeapDay(): void
    {
        $data = (new Validator(['entry_date' => '2028-02-29']))->date('entry_date')->validate();

        self::assertSame('2028-02-29', $data['entry_date']);
    }

    public function testEmailIsLowercasedAndTrimmed(): void
    {
        $data = (new Validator(['email' => '  Sana@RehmanBuilders.PK ']))->email('email')->validate();

        self::assertSame('sana@rehmanbuilders.pk', $data['email']);
    }

    public function testFalseIsAcceptedAsAPresentBoolean(): void
    {
        $data = (new Validator(['is_archived' => false]))->bool('is_archived')->validate();

        self::assertFalse($data['is_archived']);
    }
}
