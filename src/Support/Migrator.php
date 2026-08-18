<?php

declare(strict_types=1);

namespace Ledger\Support;

use PDO;
use RuntimeException;

/**
 * Applies numbered .sql files in filename order and records each with a checksum.
 *
 * Used by migrate.php and by the integration test harness, which builds its own schema
 * from the same files rather than from a snapshot that could drift.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    public function ensureTrackingTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . ' filename VARCHAR(255) NOT NULL,'
            . ' checksum CHAR(64) NOT NULL,'
            . ' applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' PRIMARY KEY (filename)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string, string> filename => checksum */
    public function applied(): array
    {
        return $this->pdo->query('SELECT filename, checksum FROM schema_migrations')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * @return list<array{name: string, sql: string, checksum: string}>
     *
     * @throws RuntimeException when a file that already ran no longer matches its checksum
     */
    public function pending(): array
    {
        $applied = $this->applied();
        $files = glob($this->directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $pending = [];

        foreach ($files as $file) {
            $name = basename($file);
            $sql = (string) file_get_contents($file);
            $checksum = hash('sha256', $sql);

            if (!isset($applied[$name])) {
                $pending[] = ['name' => $name, 'sql' => $sql, 'checksum' => $checksum];
                continue;
            }

            if (!hash_equals($applied[$name], $checksum)) {
                throw new RuntimeException(
                    "{$name} has changed since it was applied. "
                    . 'Migrations are append-only. Add a new file instead.'
                );
            }
        }

        return $pending;
    }

    /** @param array{name: string, sql: string, checksum: string} $migration */
    public function apply(array $migration): void
    {
        // MySQL commits DDL implicitly, so a failure part-way leaves the file unrecorded
        // and the operator fixes it forward. Wrapping this in a transaction would lie.
        foreach ($this->statements($migration['sql']) as $statement) {
            $this->pdo->exec($statement);
        }

        $this->pdo
            ->prepare('INSERT INTO schema_migrations (filename, checksum) VALUES (?, ?)')
            ->execute([$migration['name'], $migration['checksum']]);
    }

    /** @return int number applied */
    public function migrate(): int
    {
        $pending = $this->pending();

        foreach ($pending as $migration) {
            $this->apply($migration);
        }

        return count($pending);
    }

    /**
     * ponytail: splits on a semicolon that ends a line. Holds because every migration here
     * is plain DDL — no stored routines, no semicolons inside string literals. If one ever
     * needs them, give that file its own runner rather than growing a SQL lexer.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $chunks = preg_split('/;\s*$/m', $sql) ?: [];

        return array_values(array_filter(
            array_map('trim', $chunks),
            static fn (string $chunk): bool
                => trim((string) preg_replace('/^\s*--[^\n]*$/m', '', $chunk)) !== '',
        ));
    }
}
