<?php

declare(strict_types=1);

namespace Ledger\Support;

final class Slug
{
    /**
     * "Rehman Builders (Pvt) Ltd" becomes "rehman-builders-pvt-ltd".
     *
     * Non-ASCII names collapse to nothing here, so a name written entirely in Urdu would
     * produce an empty slug. $fallback covers that rather than the slug silently becoming
     * a bare number.
     */
    public static function from(string $text, string $fallback = 'org'): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text));
        $slug = trim($slug, '-');

        return $slug === '' ? $fallback : mb_substr($slug, 0, 130);
    }

    /**
     * @param callable(string): bool $taken
     */
    public static function unique(string $base, callable $taken): string
    {
        if (!$taken($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 999; ++$suffix) {
            $candidate = $base . '-' . $suffix;

            if (!$taken($candidate)) {
                return $candidate;
            }
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }
}
