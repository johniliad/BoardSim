<?php

declare(strict_types=1);

/**
 * Developer check: parse the YAML block of every pinned scenario file.
 *
 * Usage: php tools/smoke-yaml.php
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\Yaml;

$root = dirname(__DIR__);
$files = glob($root . '/scenarios/BS-*.md') ?: [];
sort($files);

$failed = 0;

foreach ($files as $file) {
    $markdown = (string) file_get_contents($file);

    if (preg_match('/```yaml\n(.*?)\n```/s', $markdown, $match) !== 1) {
        echo basename($file) . ": NO YAML BLOCK\n";
        $failed++;
        continue;
    }

    try {
        $data = Yaml::parse($match[1]);
        $id = $data['scenario_id'] ?? '?';
        $stages = $data['stages'] ?? [];
        $guards = $data['rule_guards'] ?? [];
        $refs = $data['regulatory_refs'] ?? [];
        printf(
            "%s  id=%s stages=%d refs=%d guards=%d brief=%s\n",
            basename($file),
            (string) $id,
            is_array($stages) ? count($stages) : -1,
            is_array($refs) ? count($refs) : -1,
            is_array($guards) ? count($guards) : -1,
            isset($data['starting_briefing']) ? strlen((string) $data['starting_briefing']) . ' chars' : 'MISSING'
        );
    } catch (Throwable $e) {
        echo basename($file) . ': ERROR ' . $e->getMessage() . "\n";
        $failed++;
    }
}

// Shape assertions for the two scenarios that carry guards.
foreach (['BS-003', 'BS-013'] as $id) {
    $markdown = (string) file_get_contents($root . "/scenarios/{$id}.md");
    preg_match('/```yaml\n(.*?)\n```/s', $markdown, $match);
    $data = Yaml::parse($match[1]);
    $guards = $data['rule_guards'] ?? [];
    $guard = $guards[0] ?? null;

    $ok = is_array($guard)
        && ($guard['stage'] ?? null) === 'S2'
        && ($guard['rule'] ?? null) === 'DR-027';

    printf(
        "%s guard check: stage=%s rule=%s -> %s\n",
        $id,
        (string) ($guard['stage'] ?? 'missing'),
        (string) ($guard['rule'] ?? 'missing'),
        $ok ? 'PASS' : 'FAIL'
    );

    if (!$ok) {
        $failed++;
    }
}

echo $failed === 0 ? "YAML SMOKE: PASS\n" : "YAML SMOKE: {$failed} FAILURE(S)\n";
exit($failed === 0 ? 0 : 1);
