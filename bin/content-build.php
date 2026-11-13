<?php

declare(strict_types=1);

/**
 * Compile the authored overlay layer into an immutable, hashed bundle.
 *
 * Usage: php bin/content-build.php [--check]
 *
 * --check loads and validates without writing, for CI.
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\CanonicalJson;
use BoardSim\Content\OverlayLoader;

$root = dirname(__DIR__);
$checkOnly = in_array('--check', $argv, true);

try {
    $content = (new OverlayLoader($root))->load();
} catch (Throwable $e) {
    fwrite(STDERR, 'BUNDLE REJECTED: ' . $e->getMessage() . "\n");
    exit(1);
}

$bundle = $content['bundle'];
$hash = $content['bundle_sha256'];

printf("Overlay version     : %s\n", (string) $bundle['regulatory_overlay_version']);
printf("Ruleset version     : %s (unchanged; no scoring delta was authored)\n", (string) $bundle['ruleset_version']);
printf("Scenarios pinned    : %d\n", count($bundle['scenario_versions']));
printf("Instruments         : %d\n", count($bundle['instruments']));
printf("Reporting duties    : %d (%s)\n",
    count($content['reporting_instrument_ids']),
    implode(', ', $content['reporting_instrument_ids'])
);
printf("Scenarios with facts: %d (%s)\n",
    count($bundle['scenario_facts']),
    implode(', ', array_keys($bundle['scenario_facts']))
);
printf("Rule definitions    : %d\n", count($bundle['rule_definitions']));
printf("Guard bindings      : %d\n", array_sum(array_map(
    static fn (array $stages): int => array_sum(array_map(
        static fn (array $rules): int => array_sum(array_map('count', $rules)),
        $stages
    )),
    $bundle['guard_bindings']
)));
printf("Bundle SHA-256      : %s\n", $hash);

if ($checkOnly) {
    echo "CHECK ONLY: bundle validated, nothing written\n";
    exit(0);
}

$payload = CanonicalJson::encode($bundle);
$directory = $root . '/content/bundles';

if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Cannot create {$directory}\n");
    exit(1);
}

$target = $directory . '/' . $bundle['regulatory_overlay_version'] . '.json';

// Written without a trailing newline so that sha256(file on disk) equals the
// declared bundle_sha256 exactly, with no normalisation step.
file_put_contents($target, $payload);
file_put_contents($target . '.sha256', $hash . '  ' . basename($target) . "\n");

printf("Wrote               : %s\n", $target);
printf("Wrote               : %s.sha256\n", basename($target));
