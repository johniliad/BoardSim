<?php

declare(strict_types=1);

/**
 * Static migration-contract verification.
 *
 * MySQL is not available in every development environment, but the most damaging
 * migration regressions are visible from source: duplicate future columns in a
 * historical base migration, missing self-registration, omitted deployment files,
 * or health reporting ready without the latest migration.
 */

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function checkMigration(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        printf("  PASS  %s\n", $label);

        return;
    }

    $failed++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : " ({$detail})");
}

$files = glob($root . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
$names = array_map('basename', $files);
$expected = [
    '001_init.sql',
    '002_events.sql',
    '003_share_tokens.sql',
    '004_rate_limits.sql',
    '005_release_integrity.sql',
];

echo "== Migration order and bookkeeping ==\n";
checkMigration('the complete ordered migration set is present', $names === $expected, implode(', ', $names));

foreach ($files as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    checkMigration(
        "{$name} records itself",
        str_contains($sql, "VALUES ('{$name}')")
    );
}

$baseRuns = (string) file_get_contents($root . '/migrations/001_init.sql');
$baseEvents = (string) file_get_contents($root . '/migrations/002_events.sql');
$integrity = (string) file_get_contents($root . '/migrations/005_release_integrity.sql');
$runColumns = ['difficulty_views_version', 'difficulty_views_sha256', 'board_sha256'];
$eventColumns = ['request_sha256', 'option_label', 'effects_json', 'content_sha256'];

echo "\n== Release-integrity upgrade ownership ==\n";

foreach ($runColumns as $column) {
    checkMigration(
        "001 does not pre-create {$column}",
        preg_match('/^\s*' . preg_quote($column, '/') . '\s+/m', $baseRuns) !== 1
    );
    checkMigration(
        "005 adds runs.{$column}",
        preg_match('/ADD COLUMN\s+' . preg_quote($column, '/') . '\b/i', $integrity) === 1
    );
}

foreach ($eventColumns as $column) {
    checkMigration(
        "002 does not pre-create {$column}",
        preg_match('/^\s*' . preg_quote($column, '/') . '\s+/m', $baseEvents) !== 1
    );
    checkMigration(
        "005 adds run_events.{$column}",
        preg_match('/ADD COLUMN\s+' . preg_quote($column, '/') . '\b/i', $integrity) === 1
    );
}

checkMigration(
    '005 converts recorded_at to a byte-preserving string',
    preg_match('/MODIFY COLUMN\s+recorded_at\s+VARCHAR\(27\).*ascii_bin/i', $integrity) === 1
);

echo "\n== Deployment and readiness integration ==\n";
$store = (string) file_get_contents($root . '/src/Infrastructure/MysqlRunStore.php');
$build = (string) file_get_contents($root . '/bin/build-deploy.php');
$migrate = (string) file_get_contents($root . '/bin/migrate.php');

checkMigration(
    'health readiness requires 005_release_integrity.sql',
    str_contains($store, "'005_release_integrity.sql'")
);

foreach (array_merge($runColumns, $eventColumns) as $column) {
    checkMigration(
        "health readiness checks {$column}",
        str_contains($store, "'{$column}'")
    );
}

checkMigration(
    'the deployment builder assembles every migration into install.sql',
    str_contains($build, "glob(\$root . '/migrations/*.sql')")
);
checkMigration(
    'the reconciliation job discovers every migration',
    str_contains($migrate, "glob(\$root . '/migrations/*.sql')")
);

printf("\nMIGRATIONS: %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);