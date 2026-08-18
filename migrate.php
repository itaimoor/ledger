<?php

/**
 * Applies pending migrations in filename order.
 *
 *   php migrate.php [--dry-run]
 *
 * Every applied file is recorded with a checksum. Editing a migration that has already
 * run is rejected rather than silently ignored — migrations are append-only.
 */

declare(strict_types=1);

use Ledger\Support\Database;
use Ledger\Support\Env;
use Ledger\Support\Migrator;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

$options = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $options, true);
$unknown = array_diff($options, ['--dry-run']);

if ($unknown !== []) {
    fwrite(STDERR, 'Unknown option: ' . reset($unknown) . PHP_EOL);
    fwrite(STDERR, 'Usage: php migrate.php [--dry-run]' . PHP_EOL);
    exit(1);
}

try {
    Env::load(__DIR__ . '/.env');

    $migrator = new Migrator(Database::connect(), __DIR__ . '/migrations');
    $migrator->ensureTrackingTable();

    $pending = $migrator->pending();

    if ($pending === []) {
        echo 'Nothing to migrate. ' . count($migrator->applied()) . ' migration(s) already applied.' . PHP_EOL;
        exit(0);
    }

    foreach ($pending as $migration) {
        if ($dryRun) {
            echo "would apply  {$migration['name']}" . PHP_EOL;
            continue;
        }

        echo "applying     {$migration['name']} ... ";
        $migrator->apply($migration);
        echo 'ok' . PHP_EOL;
    }

    echo ($dryRun ? count($pending) . ' pending.' : 'Done.') . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL . 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
