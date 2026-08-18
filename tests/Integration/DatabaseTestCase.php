<?php

declare(strict_types=1);

namespace Ledger\Tests\Integration;

use Ledger\Support\Database;
use Ledger\Support\Env;
use Ledger\Support\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base for tests that need a real database.
 *
 * The schema is built from the migration files themselves, so the tests exercise the
 * same DDL production runs rather than a snapshot that could drift.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        if (self::$pdo instanceof PDO) {
            return;
        }

        // Every table gets truncated between tests, so pointing this at the development
        // database would silently destroy it. The name must say it is disposable.
        $database = Env::string('DB_NAME');

        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run integration tests against '{$database}'. "
                . 'Create .env.testing with a DB_NAME ending in _test.'
            );
        }

        self::$pdo = Database::connect();

        $migrator = new Migrator(self::$pdo, dirname(__DIR__, 2) . '/migrations');
        $migrator->ensureTrackingTable();
        $migrator->migrate();
    }

    protected function setUp(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if ($table !== 'schema_migrations') {
                $pdo->exec("TRUNCATE TABLE {$table}");
            }
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected static function pdo(): PDO
    {
        return self::$pdo ?? throw new RuntimeException('Database not initialised.');
    }

    protected function makeUser(string $email = 'sana@rehmanbuilders.pk', string $password = 'Password123!'): int
    {
        $pdo = self::pdo();
        $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)')
            ->execute(['Sana Rehman', $email, password_hash($password, PASSWORD_ARGON2ID)]);

        return (int) $pdo->lastInsertId();
    }
}
