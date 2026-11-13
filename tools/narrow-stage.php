<?php

declare(strict_types=1);

/**
 * Which third rule would close a narrow dominance gap, without overtaking the winner?
 *
 * Numerator arithmetic is additive: adding rule R to an option shifts every run's
 * numerator by exactly sum(weight * delta). So the shortlist can be computed without
 * re-scoring 54 runs, and the exact effect is confirmed afterwards by
 * `tools/dominance-report.php`.
 *
 * Usage: php tools/narrow-stage.php BS-002 S1
 */

require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;

$root = dirname(__DIR__);
$scenarioId = $argv[1] ?? 'BS-002';
$stage = $argv[2] ?? 'S1';

$policy = (new ScoringPolicyLoader($root))->load();
$pinned = (new PinnedScenarios($root))->loadAll();
$regulatory = (new OverlayLoader($root))->load();
$scenario = $pinned[$scenarioId] ?? null;

if ($scenario === null) {
    fwrite(STDERR, "Unknown scenario {$scenarioId}\n");
    exit(1);
}

$options = (array) $scenario['stages'][(int) substr($stage, 1) - 1]['options'];
$guarded = array_keys((array) ($regulatory['guard_bindings'] ?? []));

$weighted = static function (string $ruleId) use ($policy): float {
    $sum = 0;
    foreach ($policy['ruleset']->get($ruleId)->deltas as $dimension => $delta) {
        $sum += ((int) ($policy['weights'][$dimension] ?? 0)) * (int) $delta;
    }

    return $sum / 100;
};

printf("%s %s\n\n", $scenarioId, $stage);

$totals = [];
foreach ($options as $option) {
    $id = (string) $option['id'];
    $rules = array_map('strval', (array) ($option['rules'] ?? []));
    $sum = 0.0;

    printf("  %s  %s\n", $id, substr((string) $option['label'], 0, 66));

    foreach ($rules as $ruleId) {
        $points = $weighted($ruleId);
        $sum += $points;
        printf("       %-8s %+5.2f  %s\n", $ruleId, $points, substr($policy['ruleset']->get($ruleId)->title, 0, 58));
    }

    $totals[$id] = $sum;
    printf("       %-8s %+5.2f  total numerator effect\n\n", '', $sum);
}

arsort($totals);
$ranked = array_keys($totals);
$winner = $ranked[0];
$runnerUp = $ranked[1];
$gap = $totals[$winner] - $totals[$runnerUp];

printf("  numerator gap %s -> %s: %.2f points\n\n", $winner, $runnerUp, $gap);

$used = array_map('strval', (array) ($options[0]['rules'] ?? []));
foreach ($options as $option) {
    if ((string) $option['id'] === $runnerUp) {
        $used = array_map('strval', (array) ($option['rules'] ?? []));
    }
}

echo "  candidates for {$runnerUp} — unused, unguarded, positive, and small enough to stay behind:\n";

$candidates = [];
foreach ($pinned as $each) {
    foreach ((array) $each['stages'] as $eachStage) {
        foreach ((array) $eachStage['options'] as $option) {
            foreach ((array) ($option['rules'] ?? []) as $ruleId) {
                $ruleId = (string) $ruleId;

                if (in_array($ruleId, $used, true) || in_array($ruleId, $guarded, true)) {
                    continue;
                }

                $rule = $policy['ruleset']->get($ruleId);

                if ($rule->isBoardRule()) {
                    continue;
                }

                $points = $weighted($ruleId);

                if ($points <= 0 || $points >= $gap) {
                    continue;
                }

                $candidates[$ruleId] = ['points' => $points, 'title' => $rule->title];
            }
        }
    }
}

uasort($candidates, static fn (array $a, array $b): int => $b['points'] <=> $a['points']);

foreach ($candidates as $ruleId => $candidate) {
    printf("    %-8s %+5.2f -> gap %.2f  %s\n", $ruleId, $candidate['points'], $gap - $candidate['points'], substr($candidate['title'], 0, 56));
}

if ($candidates === []) {
    echo "    none: every candidate either exceeds the gap or adds nothing\n";
}
