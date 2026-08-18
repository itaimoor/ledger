<?php

declare(strict_types=1);

namespace Ledger\Support;

use PDO;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        // Both clocks, pinned together, in the one place that owns the connection.
        //
        // Expiry is decided in two ways in this codebase: some checks compare a stored
        // timestamp against PHP's time(), others filter with MySQL's NOW(). On a default
        // XAMPP install those clocks sit hours apart — MySQL follows the system zone while
        // PHP follows date.timezone — so an invite would disappear from the members list
        // hours before it actually stopped working. UTC on both sides removes the question.
        // Timestamps are rendered in the reader's own zone by the browser.
        date_default_timezone_set('UTC');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Env::required('DB_HOST'),
            Env::int('DB_PORT', 3306),
            Env::required('DB_NAME'),
        );

        self::$pdo = new PDO($dsn, Env::required('DB_USER'), Env::string('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // real server-side prepares; emulation would re-introduce string interpolation
            PDO::ATTR_EMULATE_PREPARES => false,
            // keeps BIGINT paisa arriving as PHP int rather than string
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ]);

        return self::$pdo;
    }
}
