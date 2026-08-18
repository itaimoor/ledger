<?php

declare(strict_types=1);

namespace Ledger\Tests\Unit;

use Ledger\Support\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    #[DataProvider('names')]
    public function testBuildsAUrlSafeSlug(string $name, string $expected): void
    {
        self::assertSame($expected, Slug::from($name));
    }

    /** @return iterable<string, array{string, string}> */
    public static function names(): iterable
    {
        yield 'plain' => ['Rehman Builders', 'rehman-builders'];
        yield 'punctuation' => ['Rehman Builders (Pvt) Ltd', 'rehman-builders-pvt-ltd'];
        yield 'ampersand' => ['Hasan & Co.', 'hasan-co'];
        yield 'leading and trailing noise' => ['  --Zenith--  ', 'zenith'];
        yield 'digits kept' => ['Block 4 Builders', 'block-4-builders'];
        yield 'runs collapse' => ['A   B', 'a-b'];
    }

    /** A name written entirely in Urdu collapses to nothing, so the fallback must hold. */
    public function testANonLatinNameStillProducesAUsableSlug(): void
    {
        self::assertSame('org', Slug::from('رحمان بلڈرز'));
        self::assertSame('company', Slug::from('株式会社', 'company'));
    }

    public function testTheSlugIsBounded(): void
    {
        self::assertLessThanOrEqual(130, mb_strlen(Slug::from(str_repeat('Rehman ', 60))));
    }

    public function testUniqueReturnsTheBaseWhenItIsFree(): void
    {
        self::assertSame('rehman', Slug::unique('rehman', static fn (): bool => false));
    }

    public function testUniqueCountsUpPastCollisions(): void
    {
        $taken = ['rehman', 'rehman-2', 'rehman-3'];

        self::assertSame('rehman-4', Slug::unique('rehman', static fn (string $s): bool => in_array($s, $taken, true)));
    }

    /** With every numbered variant taken it must still terminate, not spin. */
    public function testUniqueFallsBackToRandomnessRatherThanLooping(): void
    {
        $slug = Slug::unique('rehman', static fn (): bool => true);

        self::assertStringStartsWith('rehman-', $slug);
        self::assertMatchesRegularExpression('/^rehman-[0-9a-f]{8}$/', $slug);
    }
}
