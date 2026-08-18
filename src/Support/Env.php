<?php

declare(strict_types=1);

namespace Ledger\Support;

use RuntimeException;

/**
 * Reads a .env file into process-private storage.
 *
 * Values are deliberately not pushed into putenv()/$_ENV/$_SERVER: those are inherited
 * by anything the process spawns and are readable from phpinfo() and error handlers.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read {$path}. Copy .env.example to .env first.");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        // A UTF-8 BOM would otherwise become part of the first key, silently blanking the
        // first variable in the file. Windows editors add one without asking.
        $contents = (string) preg_replace('/\A\x{FEFF}/u', '', $contents);

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                self::$vars[$key] = $value;
            }
        }
    }

    public static function string(string $key, string $default = ''): string
    {
        return self::$vars[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::$vars[$key] ?? '';

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        return match (strtolower(self::$vars[$key] ?? '')) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => $default,
        };
    }

    /** @throws RuntimeException when the key is absent or empty. */
    public static function required(string $key): string
    {
        $value = self::$vars[$key] ?? '';
        if ($value === '') {
            throw new RuntimeException("Missing required environment variable {$key}.");
        }

        return $value;
    }
}
