<?php

/**
 * Local development data. Wipes every tenant table and rebuilds it.
 *
 *   php seed.php
 *
 * Refuses to run unless APP_ENV=local.
 */

declare(strict_types=1);

use Ledger\Support\Database;
use Ledger\Support\Env;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/vendor/autoload.php';

const SEED_PASSWORD = 'Password123!';

try {
    Env::load(__DIR__ . '/.env');

    if (Env::string('APP_ENV') !== 'local') {
        fwrite(STDERR, 'seed.php only runs when APP_ENV=local. Refusing.' . PHP_EOL);
        exit(1);
    }

    $pdo = Database::connect();

    // Deterministic pseudo-randomness: the same seed always produces the same book, so a
    // balance you eyeball today is the balance a test asserts tomorrow.
    $state = 20260817;
    $pick = static function (int $bound) use (&$state): int {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

        return intdiv($state, 65536) % $bound;
    };

    $tables = [
        'activity_log',
        'entries',
        'categories',
        'projects',
        'invites',
        'refresh_tokens',
        'organization_members',
        'organizations',
        'users',
        'rate_limits',
    ];

    echo 'Wiping: ' . implode(', ', $tables) . PHP_EOL;
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $pdo->exec("TRUNCATE TABLE {$table}");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $hash = password_hash(SEED_PASSWORD, PASSWORD_ARGON2ID);

    $insertUser = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)'
    );
    $userId = static function (string $name, string $email) use ($pdo, $insertUser, $hash): int {
        $insertUser->execute([$name, $email, $hash]);

        return (int) $pdo->lastInsertId();
    };

    $sana = $userId('Sana Rehman', 'sana@rehmanbuilders.pk');
    $faisal = $userId('Faisal Ahmed', 'faisal@rehmanbuilders.pk');
    $bilal = $userId('Bilal Akhtar', 'bilal@rehmanbuilders.pk');
    $zara = $userId('Zara Khan', 'zara@rehmanbuilders.pk');
    $imran = $userId('Imran Sheikh', 'imran@sheikhassociates.pk');

    $insertOrg = $pdo->prepare('INSERT INTO organizations (name, slug, created_by) VALUES (?, ?, ?)');
    $insertMember = $pdo->prepare(
        'INSERT INTO organization_members (org_id, user_id, role) VALUES (?, ?, ?)'
    );

    $insertOrg->execute(['Rehman Builders (Pvt) Ltd', 'rehman-builders', $sana]);
    $rehman = (int) $pdo->lastInsertId();

    $insertOrg->execute(['Sheikh Associates', 'sheikh-associates', $imran]);
    $sheikh = (int) $pdo->lastInsertId();

    foreach (
        [
            [$rehman, $sana, 'owner'],
            [$rehman, $faisal, 'admin'],
            [$rehman, $bilal, 'accountant'],
            [$rehman, $zara, 'viewer'],
            [$sheikh, $imran, 'owner'],
            // Sana belongs to both orgs, so the switcher has something to switch between
            [$sheikh, $sana, 'viewer'],
        ] as $member
    ) {
        $insertMember->execute($member);
    }

    $insertCategory = $pdo->prepare('INSERT INTO categories (org_id, name, type) VALUES (?, ?, ?)');
    $categoryId = static function (int $org, string $name, string $type) use ($pdo, $insertCategory): int {
        $insertCategory->execute([$org, $name, $type]);

        return (int) $pdo->lastInsertId();
    };

    $cat = [];
    foreach (['Labour', 'Material', 'Transport', 'Fuel', 'Rent', 'Misc'] as $name) {
        $cat[$name] = $categoryId($rehman, $name, 'out');
    }
    $cat['Client payment'] = $categoryId($rehman, 'Client payment', 'in');

    $sheikhCat = [];
    foreach (['Labour', 'Material', 'Misc'] as $name) {
        $sheikhCat[$name] = $categoryId($sheikh, $name, 'out');
    }

    $insertProject = $pdo->prepare(
        'INSERT INTO projects (org_id, name, client_name, description, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $projectId = static function (array $row) use ($pdo, $insertProject): int {
        $insertProject->execute($row);

        return (int) $pdo->lastInsertId();
    };

    $villa = $projectId([
        $rehman,
        'DHA Phase 6, Villa 214',
        'Maj. (R) Tariq Aziz',
        'Grey structure through to finishing. Two-kanal double storey.',
        'active',
        $sana,
    ]);
    $bahria = $projectId([
        $rehman,
        'Bahria Town Overseas Block, Plot 88',
        'Ayesha Malik',
        'Single storey, handed over in phases.',
        'active',
        $sana,
    ]);
    $gulberg = $projectId([
        $rehman,
        'Gulberg III Office Fit-out',
        'Zenith Textiles (Pvt) Ltd',
        'Fourth floor, 6,200 sq ft.',
        'active',
        $faisal,
    ]);
    $askari = $projectId([
        $rehman,
        'Askari X, Apartment 4B',
        'Col. (R) Nadeem Baig',
        'Renovation. Closed out in March.',
        'completed',
        $sana,
    ]);
    $modelTown = $projectId([
        $rehman,
        'Model Town Boundary Wall',
        'Rehman Family Trust',
        'Small job, kept for the record.',
        'archived',
        $sana,
    ]);
    $clifton = $projectId([
        $sheikh,
        'Clifton Block 4, Beach House',
        'Sheikh Family',
        'Belongs to the other organization. Nobody in Rehman Builders may see it.',
        'active',
        $imran,
    ]);

    $insertEntry = $pdo->prepare(
        'INSERT INTO entries
            (org_id, project_id, type, amount_paisa, category_id, description,
             entry_date, reconciles_entry_id, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertActivity = $pdo->prepare(
        'INSERT INTO activity_log (org_id, user_id, action, entity_type, entity_id, after_json, ip, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $entryCount = 0;
    $addEntry = static function (
        int $org,
        int $project,
        string $type,
        int $rupees,
        ?int $category,
        string $description,
        string $date,
        int $author,
        ?int $reconciles = null,
    ) use (
        $pdo,
        $insertEntry,
        $insertActivity,
        &$entryCount
    ): int {
        $paisa = $rupees * 100;
        $createdAt = $date . ' 09:00:00';

        $insertEntry->execute([
            $org, $project, $type, $paisa, $category, $description,
            $date, $reconciles, $author, $createdAt,
        ]);
        $id = (int) $pdo->lastInsertId();

        $insertActivity->execute([
            $org,
            $author,
            $reconciles === null ? 'entry.created' : 'entry.reconciled',
            'entry',
            $id,
            json_encode([
                'type' => $type,
                'amount_paisa' => $paisa,
                'entry_date' => $date,
                'project_id' => $project,
            ], JSON_THROW_ON_ERROR),
            '127.0.0.1',
            $createdAt,
        ]);

        ++$entryCount;

        return $id;
    };

    // Amount range is per category, so a bag of cement never costs what a crane does and
    // the books come out roughly where a real project's would.
    $outKinds = [
        'Labour' => [50000, 200000, [
            'Mason team weekly wages', 'Steel fixers, 6 men', 'Painters advance', 'Site labour, 11 men',
        ]],
        'Material' => [100000, 400000, [
            'Steel bars 40 ton — Ittefaq Steel', 'Cement 400 bags — Maple Leaf',
            'Sand and crush, 4 trips', 'Tiles — Master Ceramics',
        ]],
        'Transport' => [10000, 60000, ['Truck hire to site', 'Crane half-day', 'Debris removal']],
        'Fuel' => [5000, 25000, ['Generator diesel 120 L', 'Site vehicle petrol']],
        'Rent' => [40000, 120000, ['Shuttering hire, monthly', 'Site office container']],
        'Misc' => [2500, 22500, ['Municipal NOC fee', 'Tea and refreshments, site', 'Tool replacement']],
    ];
    $authors = [$sana, $faisal, $bilal];

    /** @return array{0: string, 1: int, 2: string} category name, rupees, description */
    $spend = static function (array $kinds) use ($pick): array {
        $name = array_keys($kinds)[$pick(count($kinds))];
        [$min, $max, $notes] = $kinds[$name];

        return [
            $name,
            $min + $pick(intdiv($max - $min, 500) + 1) * 500,
            $notes[$pick(count($notes))],
        ];
    };

    // The busy project: enough rows that cursor pagination and the running balance have
    // something real to chew on, including several entries sharing one date.
    $day = new DateTimeImmutable('2025-11-03');

    for ($i = 0; $i < 176; ++$i) {
        $day = $day->modify('+' . $pick(3) . ' day');
        $date = $day->format('Y-m-d');
        $author = $authors[$pick(count($authors))];

        if ($i % 11 === 0) {
            $addEntry(
                $rehman,
                $villa,
                'in',
                600000 + $pick(81) * 10000,
                $cat['Client payment'],
                'Client instalment — Maj. (R) Tariq Aziz',
                $date,
                $author,
            );
            continue;
        }

        [$name, $rupees, $note] = $spend($outKinds);
        $addEntry($rehman, $villa, 'out', $rupees, $cat[$name], $note, $date, $author);
    }

    // A wrong entry and the reconciling entry that corrects it. The pair stays in the book.
    $mistake = $addEntry(
        $rehman,
        $villa,
        'out',
        850000,
        $cat['Material'],
        'Cement 400 bags — amount entered with a stray zero',
        '2026-07-14',
        $bilal,
    );
    $addEntry(
        $rehman,
        $villa,
        'in',
        765000,
        null,
        'Reconciles entry #' . $mistake . ' — overstated by Rs 765,000',
        '2026-07-15',
        $faisal,
        $mistake,
    );

    $smallBooks = [
        [$bahria, 26, '2026-01-08', $rehman],
        [$gulberg, 34, '2026-03-02', $rehman],
        [$askari, 19, '2025-09-15', $rehman],
        [$modelTown, 7, '2025-06-11', $rehman],
    ];

    foreach ($smallBooks as [$project, $count, $start, $org]) {
        $day = new DateTimeImmutable($start);

        for ($i = 0; $i < $count; ++$i) {
            $day = $day->modify('+' . (1 + $pick(5)) . ' day');
            $date = $day->format('Y-m-d');
            $author = $authors[$pick(count($authors))];

            if ($i % 8 === 0) {
                $paid = 400000 + $pick(61) * 10000;
                $addEntry($org, $project, 'in', $paid, $cat['Client payment'], 'Client instalment', $date, $author);
                continue;
            }

            [$name, $rupees, $note] = $spend($outKinds);
            $addEntry($org, $project, 'out', $rupees, $cat[$name], $note, $date, $author);
        }
    }

    $cliftonKinds = array_intersect_key($outKinds, $sheikhCat);
    $day = new DateTimeImmutable('2026-02-04');

    for ($i = 0; $i < 12; ++$i) {
        $day = $day->modify('+' . (2 + $pick(6)) . ' day');
        $date = $day->format('Y-m-d');

        if ($i % 5 === 0) {
            $addEntry($sheikh, $clifton, 'in', 700000, null, 'Client instalment', $date, $imran);
            continue;
        }

        [$name, $rupees, $note] = $spend($cliftonKinds);
        $addEntry($sheikh, $clifton, 'out', $rupees, $sheikhCat[$name], $note, $date, $imran);
    }

    foreach (
        [
            [$rehman, $sana, 'organization.created', 'organization', $rehman],
            [$rehman, $sana, 'member.added', 'user', $faisal],
            [$rehman, $sana, 'member.added', 'user', $bilal],
            [$rehman, $sana, 'member.added', 'user', $zara],
            [$rehman, $sana, 'project.created', 'project', $villa],
            [$sheikh, $imran, 'organization.created', 'organization', $sheikh],
        ] as [$org, $actor, $action, $entityType, $entityId]
    ) {
        $insertActivity->execute([
            $org, $actor, $action, $entityType, $entityId, null, '127.0.0.1', '2025-06-01 10:00:00',
        ]);
    }

    $totals = $pdo->query(
        "SELECT
             SUM(type = 'in') AS ins,
             SUM(type = 'out') AS outs,
             SUM(CASE WHEN type = 'in' THEN amount_paisa ELSE -amount_paisa END) AS balance_paisa
         FROM entries"
    )->fetch();

    echo PHP_EOL;
    echo "Seeded {$entryCount} entries across 6 projects in 2 organizations." . PHP_EOL;
    echo 'Net across every book: Rs ' . number_format(((int) $totals['balance_paisa']) / 100, 2) . PHP_EOL;
    echo PHP_EOL;
    echo 'Sign in with password: ' . SEED_PASSWORD . PHP_EOL;
    echo '  sana@rehmanbuilders.pk     owner  in Rehman Builders, viewer in Sheikh Associates' . PHP_EOL;
    echo '  faisal@rehmanbuilders.pk   admin' . PHP_EOL;
    echo '  bilal@rehmanbuilders.pk    accountant' . PHP_EOL;
    echo '  zara@rehmanbuilders.pk     viewer' . PHP_EOL;
    echo '  imran@sheikhassociates.pk  owner  of Sheikh Associates only' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
