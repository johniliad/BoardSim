<?php

declare(strict_types=1);

/**
 * Verify that the 28 SHA-256 pinned specification files are unmodified.
 *
 * SHA256_MANIFEST.md lists the requirement files with a path relative to
 * requirements/, while engine/ and scenarios/ entries are relative to the
 * project root. Both bases are resolved here, and a missing file is a failure.
 *
 * Usage: php bin/verify-manifest.php
 */
require __DIR__ . '/../src/bootstrap.php';

$root = dirname(__DIR__);
$manifestPath = $root . '/requirements/SHA256_MANIFEST.md';

if (!is_file($manifestPath)) {
    fwrite(STDERR, "Missing SHA256_MANIFEST.md\n");
    exit(1);
}

$manifest = (string) file_get_contents($manifestPath);
preg_match_all('/^([0-9a-f]{64})\s+(\S+)$/m', $manifest, $matches, PREG_SET_ORDER);

if ($matches === []) {
    fwrite(STDERR, "No manifest entries parsed\n");
    exit(1);
}

$ok = 0;
$failed = 0;

foreach ($matches as $entry) {
    $expected = $entry[1];
    $relative = $entry[2];

    // The manifest lists requirement files with a bare name (relative to
    // requirements/) and prefixes engine/ and scenarios/ paths. Resolve by that
    // convention, not by "whichever file exists first": a root-level README.md
    // must never shadow requirements/README.md.
    $candidates = str_contains($relative, '/')
        ? [$root . '/' . $relative, $root . '/requirements/' . $relative]
        : [$root . '/requirements/' . $relative, $root . '/' . $relative];

    $resolved = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $resolved = $candidate;
            break;
        }
    }

    if ($resolved === null) {
        printf("MISSING   %s\n", $relative);
        $failed++;
        continue;
    }

    $actual = hash_file('sha256', $resolved);

    if ($actual === $expected) {
        $ok++;
        printf("OK        %s\n", $relative);
        continue;
    }

    printf("MISMATCH  %s\n  expected %s\n  actual   %s\n", $relative, $expected, $actual);
    $failed++;
}

printf("\nMANIFEST: %d verified, %d failed, %d total\n", $ok, $failed, count($matches));

exit($failed === 0 ? 0 : 1);
