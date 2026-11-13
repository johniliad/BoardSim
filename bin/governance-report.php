<?php

declare(strict_types=1);

/**
 * Generates reports/governance-signoff.md from the *resolved* bundle.
 *
 * Derived rather than hand-written on purpose: a register maintained by hand drifts
 * from what the engine actually loads, and then a reviewer signs a document that
 * describes a system that no longer exists. This cannot drift.
 *
 * Usage: php bin/governance-report.php
 */

require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\OverlayLoader;

$root = dirname(__DIR__);
$regulatory = (new OverlayLoader($root))->load();

$bundleFile = $root . '/content/bundles/1.1.0.json';
$bundleHash = 'unknown';

if (is_file($bundleFile . '.sha256')) {
    $bundleHash = explode(' ', trim((string) file_get_contents($bundleFile . '.sha256')))[0];
} elseif (is_file($bundleFile)) {
    $bundleHash = hash_file('sha256', $bundleFile);
}

$rows = [];
$signed = 0;
$gated = 0;
$duty = 0;

foreach ($regulatory['instruments'] as $instrument) {
    $requiresGate = $instrument->requiresCounselGate();

    if ($instrument->counselSignedOff) {
        $signed++;
    }

    if ($requiresGate) {
        $gated++;
    }

    if ($instrument->reportingDuty) {
        $duty++;
    }

    $rows[] = sprintf(
        '| `%s` | %s | %s | %s | %s | %s | %s |',
        $instrument->id,
        str_replace('|', '/', $instrument->title),
        $instrument->nature,
        $instrument->verificationTier,
        $instrument->reportingDuty ? 'yes' : 'no',
        $instrument->counselSignedOff ? 'SIGNED' : '**NOT SIGNED**',
        $requiresGate ? 'requires counsel gate' : '—'
    );
}

$report = "# Governance sign-off register\n\n"
    . 'Generated from the resolved regulatory bundle. Do not edit by hand: run '
    . "`php bin/governance-report.php`.\n\n"
    . sprintf("Generated: %s\n", gmdate('Y-m-d\TH:i:s\Z'))
    . sprintf("Bundle: %s\n\n", $bundleHash)
    . sprintf("**Signed: %d of %d instruments.** %d require a counsel gate. %d carry a reporting duty.\n\n",
        $signed, count($rows), $gated, $duty)
    . "A blank signature column is not an oversight to be quietly filled in. Every row here "
    . "starts unsigned and stays unsigned until a named human records a dated verdict in "
    . "`docs/governance-protocol.md`.\n\n"
    . "| Anchor | Instrument | Nature | Tier | Reporting duty | Counsel sign-off | Gate |\n"
    . "| --- | --- | --- | --- | --- | --- | --- |\n"
    . implode("\n", $rows)
    . "\n";

$target = $root . '/reports/governance-signoff.md';
@mkdir(dirname($target), 0775, true);
file_put_contents($target, $report);

printf("%s\n", $report);
printf("\nWritten to %s\n", $target);
