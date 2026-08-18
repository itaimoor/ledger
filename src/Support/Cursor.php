<?php

declare(strict_types=1);

namespace Ledger\Support;

use DateTimeImmutable;
use Ledger\Exceptions\ValidationException;

/**
 * Opaque position in a list sorted by (sort value DESC, id DESC).
 *
 * Offset pagination would skip or repeat rows as records are added, and both the book and
 * the activity log are append-only, so rows are added constantly. Keying on the sort tuple
 * itself means page 2 resumes exactly where page 1 stopped no matter what happened in
 * between.
 *
 * The sort value is a date for entries and a datetime for activity; both formats are
 * accepted and nothing else is.
 */
final class Cursor
{
    private const FORMATS = ['!Y-m-d' => 10, '!Y-m-d H:i:s' => 19];

    public static function encode(string $sortValue, int $id): string
    {
        return rtrim(strtr(base64_encode($sortValue . '|' . $id), '+/', '-_'), '=');
    }

    /**
     * @return array{value: string, id: int}|null
     *
     * @throws ValidationException when the client sends something that is not a cursor
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode('|', $decoded);

        if (count($parts) !== 2 || !ctype_digit($parts[1]) || !self::isRealTimestamp($parts[0])) {
            throw new ValidationException(['cursor' => 'That is not a valid cursor.']);
        }

        return ['value' => $parts[0], 'id' => (int) $parts[1]];
    }

    /**
     * createFromFormat rolls 2026-02-30 forward to 2 March rather than failing, so a value
     * is only real if it formats back to exactly what came in.
     */
    private static function isRealTimestamp(string $value): bool
    {
        foreach (self::FORMATS as $format => $length) {
            if (strlen($value) !== $length) {
                continue;
            }

            $parsed = DateTimeImmutable::createFromFormat($format, $value);

            if ($parsed !== false && $parsed->format(ltrim($format, '!')) === $value) {
                return true;
            }
        }

        return false;
    }
}
