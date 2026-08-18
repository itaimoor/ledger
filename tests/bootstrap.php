<?php

/**
 * Test bootstrap.
 *
 * .env is loaded first, then .env.testing on top of it, so a test run only has to
 * override DB_NAME. Integration tests refuse to run unless that override is present.
 */

declare(strict_types=1);

use Ledger\Support\Env;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

Env::load($root . '/.env');

if (is_readable($root . '/.env.testing')) {
    Env::load($root . '/.env.testing');
}
