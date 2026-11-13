<?php

declare(strict_types=1);

/**
 * Reconciliation job for the schema and the pinned bundles.
 *
 *   php bin/migrate.php            # apply anything missing, seed bundles
 *   php bin/migrate.php --check    # report only, change nothing
 *
 * Why a recurring cron job rather than an installer: the target host has no
 * shell, no CLI access and no web installer. Deployment is "copy two directories
 * and add a cron entry", so every step that would normally be an install step has
 * to be reconcilable and idempotent instead:
 *
 *   - CREATE TABLE IF NOT EXISTS + schema_migrations  → re-running is free
 *   - INSERT IGNORE into content_bundles              → re-seeding is free
 *   - GET_LOCK('boardsim_migrate', 0)                 → overlapping runs no-op
 *
 * INTENDED FOR CRON. It never prints a credential and never appears in a public
 * response. Run it on install day every ten minutes, then daily; it exits after
 * a handful of queries when there is nothing to do.
 *
 * The reconciliation of bundles matters for correctness, not just tidiness:
 * runs.policy_sha256 has a foreign key to content_bundles.bundle_sha256, so a run
 * cannot be created until the bundle it pins is recorded.
 */

require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\BundleSeeder;
use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;
use BoardSim\Infrastructure\DatabaseHandle;
use BoardSim\Infrastructure\EnvFile;
use BoardSim\Infrastructure\MysqlRunStore;

$root = dirname(__DIR__);
$checkOnly = in_array('--check', $argv, true) || in_array('--dry-run', $argv, true);

$local = EnvFile::bootstrap($root);

$config = [
    'dsn' => (string) (getenv('BOARDSIM_DSN') ?: ($local['dsn'] ?? '')),
    'user' => (string) (getenv('BOARDSIM_USER') ?: ($local['user'] ?? '')),
    'pass' => (string) (getenv('BOARDSIM_PASS') ?: ($local['pass'] ?? '')),
];

if ($config['dsn'] === '') {
    fwrite(STDERR, "MIGRATE: FAILED — no DSN. Create config.local.php in "
        . "{$root} with dsn/user/pass (see config.local.example.php).\n");
    exit(1);
}

[$pdo, $databaseError] = DatabaseHandle::connect($config);

if ($pdo === null) {
    fwrite(STDERR, "MIGRATE: FAILED — cannot connect ({$databaseError}). "
        . "Check config.local.php; the database and the user must already exist in cPanel.\n");
    exit(1);
}

$lock = $pdo->query("SELECT GET_LOCK('boardsim_migrate', 0)")->fetchColumn();

if ((int) $lock !== 1) {
    // Another cron tick is already working. Not an error: the next tick finishes
    // whatever this one would have done.
    echo "MIGRATE: skipped — another migrate run holds the lock\n";
    exit(0);
}

/**
 * Split a migration file into statements.
 *
 * Sound for these files and deliberately narrow: they contain no stored
 * procedures, no DELIMITER blocks and no semicolons inside string literals. A
 * file that needs a DELIMITER (the optional append-only trigger) is refused
 * rather than mis-parsed — triggers are created in phpMyAdmin, not here.
 *
 * @return list<string>
 */
function migrationStatements(string $sql, string $name): array
{
    if (stripos($sql, 'DELIMITER') !== false) {
        fwrite(STDERR, "MIGRATE: FAILED — {$name} contains DELIMITER; apply it in phpMyAdmin instead.\n");
        exit(1);
    }

    $statements = [];
    $buffer = '';

    foreach (preg_split('/\r\n|\r|\n/', $sql) ?: [] as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }

        $buffer .= $line . "\n";

        if (str_ends_with(rtrim($line), ';')) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }

    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

try {
    $present = array_map('strval', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $applied = [];

    if (in_array('schema_migrations', $present, true)) {
        $applied = array_map(
            'strval',
            $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')
                ->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    $files = glob($root . '/migrations/*.sql') ?: [];
    sort($files);
    $pending = [];

    foreach ($files as $file) {
        if (!in_array(basename($file), $applied, true)) {
            $pending[] = $file;
        }
    }

    printf("MIGRATE: %s\n", $checkOnly ? 'check only, nothing will be written' : 'applying');
    printf("  tables  : %d present\n", count($present));
    printf("  applied : %s\n", $applied === [] ? '(none)' : implode(', ', $applied));
    printf("  pending : %s\n", $pending === [] ? '(none)' : implode(', ', array_map('basename', $pending)));

    if (!$checkOnly) {
        foreach ($pending as $file) {
            $name = basename($file);
            $statements = migrationStatements((string) file_get_contents($file), $name);

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            // The file records itself, but an older copy of a file may not, so the
            // bookkeeping is asserted here as well. INSERT IGNORE keeps it true
            // for a file that already does.
            $pdo->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (:m)')
                ->execute(['m' => $name]);

            printf(
                "  applied : %s (%d statement%s)\n",
                $name,
                count($statements),
                count($statements) === 1 ? '' : 's'
            );
        }
    }

    // Bundles: the rows a run pins, which must exist before any run can be
    // created because of the foreign key on runs.policy_sha256.
    $policy = (new ScoringPolicyLoader($root))->load();
    $regulatory = (new OverlayLoader($root))->load();
    $pinned = new PinnedScenarios($root);
    $store = new MysqlRunStore($pdo);

    $hashes = [
        (string) $policy['policy_sha256'],
        (string) $regulatory['bundle_sha256'],
        $pinned->contentSha256(),
    ];

    $missing = $store->missingBundles($hashes);

    if (!$checkOnly && $missing !== []) {
        BundleSeeder::seed($store, $policy, $regulatory, $pinned->contentVersion(), $pinned->contentSha256());
        $missing = $store->missingBundles($hashes);
    }

    printf("  bundles : %d/%d present\n", count($hashes) - count($missing), count($hashes));

    if ($missing !== []) {
        fwrite(STDERR, 'MIGRATE: FAILED — bundles still missing: ' . implode(', ', $missing) . "\n");
        exit(1);
    }

    printf(
        "MIGRATE: %s — content %s, ruleset %s\n",
        $checkOnly ? 'ok (nothing written)' : 'ok',
        $pinned->contentVersion(),
        (string) $policy['ruleset_version']
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'MIGRATE: FAILED — ' . $e->getMessage() . "\n");
    fwrite(STDERR, "If this is a privilege error, apply dist/install.sql in phpMyAdmin instead.\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('boardsim_migrate')");
}
