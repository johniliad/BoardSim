<?php

declare(strict_types=1);

/**
 * Authoring aid for calibration: show each rule's delta profile and how it is
 * used across the corpus (which stage-option position maps to it).
 *
 * A-usage means the rule only ever fires when the player picks the option that
 * currently dominates; B-usage means it fires on the middle-grounded option.
 *
 * Usage: php tools/rule-usage.php
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;

$root = dirname(__DIR__);
$version = $argv[1] ?? null;
$policy = (new ScoringPolicyLoader($root))->load($version);
$pinned = (new PinnedScenarios($root))->loadAll();

printf("ruleset %s\n\n", $policy['ruleset_version']);

$usage = [];
$total = [];

foreach ($pinned as $scenarioId => $scenario) {
    foreach ((array) ($scenario['stages'] ?? []) as $stage) {
        foreach ((array) ($stage['options'] ?? []) as $option) {
            $position = (string) ($option['id'] ?? '');

            foreach ((array) ($option['rules'] ?? []) as $ruleId) {
                $ruleId = (string) $ruleId;
                $usage[$ruleId][$position] = ($usage[$ruleId][$position] ?? 0) + 1;
                $total[$ruleId] = ($total[$ruleId] ?? 0) + 1;
            }
        }
    }
}

printf("%-8s %-46s %5s %4s %4s %4s  %s\n", 'rule', 'title', 'net', 'A', 'B', 'C', 'deltas');
echo str_repeat('-', 118) . "\n";

$rules = $policy['ruleset']->all();
ksort($rules);

foreach ($rules as $id => $rule) {
    $net = array_sum($rule->deltas);
    $a = $usage[$id]['A'] ?? 0;
    $b = $usage[$id]['B'] ?? 0;
    $c = $usage[$id]['C'] ?? 0;

    $parts = [];
    foreach ($rule->deltas as $dimension => $delta) {
        $parts[] = $dimension . ($delta >= 0 ? '+' : '') . $delta;
    }

    printf(
        "%-8s %-46s %+5d %4d %4d %4d  %s\n",
        $id,
        substr($rule->title, 0, 46),
        $net,
        $a,
        $b,
        $c,
        implode(' ', $parts)
    );
}

echo "\nNet magnitude by position (sum of |net delta| across firings a player sees):\n";

foreach (['A', 'B', 'C'] as $position) {
    $sum = 0;
    $count = 0;

    foreach ($usage as $ruleId => $positions) {
        if (!isset($positions[$position])) {
            continue;
        }

        $rule = $policy['ruleset']->get((string) $ruleId);
        $sum += array_sum($rule->deltas) * $positions[$position];
        $count += $positions[$position];
    }

    printf("  option %s: %d firings, net %+d (mean %+.2f per firing)\n", $position, $count, $sum, $sum / $count);
}
