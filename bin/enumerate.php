<?php

declare(strict_types=1);

/**
 * Stage 3: full enumeration and replay determinism harness.
 *
 * Covers 14 scenarios x 27 paths x 3 appetites x 2 board extremes = 2,268 runs,
 * as specified in 09_REPLAY_REPORT.md, and asserts the published distribution.
 *
 * This is a software arithmetic check, not a psychological validation.
 *
 * Usage: php bin/enumerate.php [--fast]
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\CanonicalJson;
use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;
use BoardSim\Domain\Regulation\FactSet;
use BoardSim\Domain\Regulation\GuardResolver;
use BoardSim\Domain\Regulation\RegulatoryEvaluator;
use BoardSim\Domain\Scoring\FiringBuilder;
use BoardSim\Domain\Scoring\OptionLookup;

final class E
{
    public static int $passed = 0;
    public static int $failed = 0;

    public static function check(string $label, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            printf("  PASS  %s\n", $label);

            return;
        }

        self::$failed++;
        printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : " ({$detail})");
    }
}

$fast = in_array('--fast', $argv, true);
$requestedVersion = null;
$requestedContent = null;
$requestedDifficulty = 'leadership';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--ruleset=')) {
        $requestedVersion = substr($argument, strlen('--ruleset='));
    }

    if (str_starts_with($argument, '--content=')) {
        $requestedContent = substr($argument, strlen('--content='));
    }

    if (str_starts_with($argument, '--difficulty=')) {
        $requestedDifficulty = substr($argument, strlen('--difficulty='));
    }
}

// Reproducing a historical run means pinning BOTH the scoring policy and the
// scenario content. Asking for ruleset 1.0.0 therefore implies content 1.0.0
// unless a content version is given explicitly.
if ($requestedContent === null && $requestedVersion === '1.0.0') {
    $requestedContent = '1.0.0';
}

$root = dirname(__DIR__);

$regulatory = (new OverlayLoader($root))->load();
$policy = (new ScoringPolicyLoader($root))->load($requestedVersion);
$pinnedScenarios = new PinnedScenarios($root, $requestedContent);
$pinned = $pinnedScenarios->loadAll();

// Difficulty is a projection, so it decides which board fields can fire. The
// enumeration must use the same subset the service would, or the baseline is
// measuring a board pack nobody can submit.
$views = \BoardSim\Content\DifficultyViews::load($root);

if (!$views->has($requestedDifficulty) || $views->get($requestedDifficulty)['available'] !== true) {
    fwrite(STDERR, "Difficulty '{$requestedDifficulty}' is not available\n");
    exit(1);
}

$boardFields = $views->filterFields($requestedDifficulty, $policy['board_fields']);

$lookup = new OptionLookup($pinned);
$resolver = new GuardResolver(
    $regulatory['rule_definitions'],
    $regulatory['guard_bindings'],
    $regulatory['reporting_instrument_ids']
);
$builder = new FiringBuilder($lookup, $policy['ruleset'], $resolver);
$calculator = $policy['calculator'];
$evaluator = new RegulatoryEvaluator();
$simulationDate = '2026-11-12';

// Regulatory status depends only on (scenario, stage), never on the chosen path,
// the appetite or the board answers, so it is evaluated 42 times and cached.
$statusCache = [];
$statusesFor = static function (string $scenarioId, string $stage) use (
    &$statusCache,
    $regulatory,
    $evaluator,
    $simulationDate
): array {
    $key = $scenarioId . '|' . $stage;

    if (isset($statusCache[$key])) {
        return $statusCache[$key];
    }

    $facts = FactSet::fromAuthored($regulatory['scenario_facts'][$scenarioId]['facts'], $stage);
    $statuses = [];

    foreach ($regulatory['instruments'] as $id => $instrument) {
        $statuses[$id] = $evaluator->evaluate($instrument, $facts, $simulationDate)['status'];
    }

    return $statusCache[$key] = $statuses;
};

$board = static function (bool $substantiated) use ($boardFields): array {
    $selections = [];

    foreach ($boardFields as $field => $definition) {
        foreach ($definition['options'] as $option) {
            if (($option['substantiated'] ?? false) === $substantiated) {
                $selections[(string) $field] = (string) $option['id'];
                break;
            }
        }
    }

    return $selections;
};

$boardFull = $board(true);
$boardNone = $board(false);

$execute = static function (
    string $scenarioId,
    array $path,
    array $boardSelections,
    string $appetite
) use ($builder, $calculator, $statusesFor, $boardFields): array {
    $firings = [];
    $suppressed = [];

    foreach ($path as $stage => $optionId) {
        $decision = $builder->forDecision($scenarioId, $stage, $optionId, $statusesFor($scenarioId, $stage));
        $firings = array_merge($firings, $decision['firings']);
        $suppressed = array_merge($suppressed, $decision['suppressed']);
    }

    $boardResult = $builder->forBoard($boardSelections, $boardFields);
    $firings = array_merge($firings, $boardResult['firings']);

    $result = $calculator->calculate($firings, $appetite);

    return [
        'result' => $result,
        'suppressed' => $suppressed,
        'declined' => $boardResult['declined'],
        'canonical' => CanonicalJson::encode($result),
    ];
};

$options = ['A', 'B', 'C'];
$paths = [];
foreach ($options as $first) {
    foreach ($options as $second) {
        foreach ($options as $third) {
            $paths[] = "{$first}{$second}{$third}";
        }
    }
}

$appetites = ['conservative', 'balanced', 'aggressive'];
$boardViews = ['full' => $boardFull, 'none' => $boardNone];
$runCount = count($pinned) * count($paths) * count($appetites) * count($boardViews);

printf(
    "Enumerating %d scenarios x %d paths x %d appetites x %d board views = %d runs\n",
    count($pinned),
    count($paths),
    count($appetites),
    count($boardViews),
    $runCount
);
printf(
    "  difficulty=%s  board fields=%d  ruleset=%s  content=%s\n\n",
    $requestedDifficulty,
    count($boardFields),
    (string) $policy['ruleset_version'],
    $pinnedScenarios->contentVersion()
);

$results = [];
$mismatches = [];
$optionScores = [];
$suppressionCount = 0;
$started = microtime(true);

foreach (array_keys($pinned) as $scenarioId) {
    foreach ($paths as $path) {
        $decisions = ['S1' => $path[0], 'S2' => $path[1], 'S3' => $path[2]];

        foreach ($appetites as $appetite) {
            foreach ($boardViews as $boardKey => $boardSelection) {
                $run = $execute($scenarioId, $decisions, $boardSelection, $appetite);

                if (!$fast) {
                    $replay = $execute($scenarioId, $decisions, $boardSelection, $appetite);

                    if ($replay['canonical'] !== $run['canonical']) {
                        $mismatches[] = "{$scenarioId}/{$path}/{$appetite}/{$boardKey}";
                    }
                }

                $score = (int) $run['result']['score'];
                $suppressionCount += count($run['suppressed']);

                $results[] = [
                    'scenario' => $scenarioId,
                    'path' => $path,
                    'appetite' => $appetite,
                    'board' => $boardKey,
                    'score' => $score,
                    'numerator' => (int) $run['result']['numerator'],
                ];

                foreach ($decisions as $stage => $optionId) {
                    $optionScores[$scenarioId][$stage][$optionId][] = $score;
                }
            }
        }
    }
}

$elapsed = microtime(true) - $started;

echo "== Distribution ==\n";

$values = array_column($results, 'score');
$count = count($values);
$min = min($values);
$max = max($values);
$sum = array_sum($values);
$mean = $sum / $count;

printf("  runs      : %d\n", $count);
printf("  min / max : %d / %d\n", $min, $max);
printf("  sum       : %d\n", $sum);
printf("  mean      : %.4f  (published 71.15 at 2 dp)\n", $mean);
printf("  elapsed   : %.2fs\n", $elapsed);
echo "\n";

E::check('exactly 2,268 runs enumerated', $count === 2268, (string) $count);
E::check('every score is inside 0..100', $min >= 0 && $max <= 100);
E::check(
    'no run diverges when replayed',
    $fast || $mismatches === [],
    $fast ? 'skipped' : implode(',', array_slice($mismatches, 0, 5))
);

// The published oracle in 09_REPLAY_REPORT.md belongs to ruleset 1.0.0 over
// content 1.0.0 at leadership difficulty. Any newer policy, remap or difficulty
// is a different artefact, so it is measured against its own baseline.
if ((string) $policy['ruleset_version'] === '1.0.0'
    && $pinnedScenarios->contentVersion() === '1.0.0'
    && $requestedDifficulty === 'leadership'
) {
    E::check('minimum score is 58', $min === 58, (string) $min);
    E::check('maximum score is 84', $max === 84, (string) $max);
    E::check('mean rounds to 71.15', number_format($mean, 2, '.', '') === '71.15', number_format($mean, 2));
} else {
    printf(
        "  NOTE  ruleset %s is calibrated, so the 1.0.0 oracle (58/84/71.15) does not apply\n",
        (string) $policy['ruleset_version']
    );
}

echo "\n== By appetite ==\n";
$byAppetite = [];
foreach ($results as $row) {
    $byAppetite[$row['appetite']][] = $row['score'];
}

$appetiteStats = [];
ksort($byAppetite);

foreach ($byAppetite as $appetite => $scores) {
    $mean = array_sum($scores) / count($scores);
    $appetiteStats[$appetite] = [
        'runs' => count($scores),
        'min' => min($scores),
        'max' => max($scores),
        'mean' => number_format($mean, 2, '.', ''),
    ];
    printf(
        "  %-13s runs=%4d  min=%2d  max=%2d  mean=%s\n",
        $appetite,
        count($scores),
        min($scores),
        max($scores),
        number_format($mean, 2)
    );
}

echo "\n== By scenario ==\n";
$byScenario = [];
foreach ($results as $row) {
    $byScenario[$row['scenario']][] = $row['score'];
}

$scenarioStats = [];
ksort($byScenario);

foreach ($byScenario as $scenarioId => $scores) {
    $mean = array_sum($scores) / count($scores);
    $scenarioStats[$scenarioId] = [
        'runs' => count($scores),
        'min' => min($scores),
        'max' => max($scores),
        'mean' => number_format($mean, 2, '.', ''),
    ];
    printf(
        "  %s  runs=%3d  min=%2d  max=%2d  mean=%s\n",
        $scenarioId,
        count($scores),
        min($scores),
        max($scores),
        number_format($mean, 2)
    );
}

echo "\n== Authoring flaw check (08_CONTENT_GOVERNANCE.md section 4) ==\n";
echo "  Looking for a stage option whose mean score beats every alternative by >= 1.00\n";

$dominance = [];
$buckets = 0;

foreach ($optionScores as $scenarioId => $stages) {
    foreach ($stages as $stage => $perOption) {
        $buckets++;

        $means = [];
        foreach ($perOption as $optionId => $scores) {
            $means[$optionId] = array_sum($scores) / count($scores);
        }

        arsort($means);
        $ranked = array_keys($means);

        if (count($ranked) < 2) {
            continue;
        }

        $gap = $means[$ranked[0]] - $means[$ranked[1]];

        if ($gap >= 1.0) {
            $dominance[] = [
                'scenario' => $scenarioId,
                'stage' => $stage,
                'best_option' => $ranked[0],
                'best_mean' => number_format($means[$ranked[0]], 2, '.', ''),
                'second_option' => $ranked[1],
                'second_mean' => number_format($means[$ranked[1]], 2, '.', ''),
                'gap' => number_format($gap, 2, '.', ''),
            ];

            printf(
                "  DOMINANT  %s %s: option %s (mean %s) beats %s (mean %s) by %s\n",
                $scenarioId,
                $stage,
                $ranked[0],
                number_format($means[$ranked[0]], 2),
                $ranked[1],
                number_format($means[$ranked[1]], 2),
                number_format($gap, 2)
            );
        }
    }
}

if ($dominance === []) {
    echo "  none: no stage has a clearly dominant option\n";
}

E::check('flaw check covered every scenario and stage', $buckets === 14 * 3, (string) $buckets);

echo "\n== Guard behaviour across the corpus ==\n";
printf("  guard suppressions observed: %d\n", $suppressionCount);
E::check(
    'no path silently loses a rule to an unmet guard',
    $suppressionCount === 0,
    (string) $suppressionCount
);

echo "\n== Score vs number of 'A' choices ==\n";
echo "  If score is driven mainly by picking A, the exercise is gameable.\n";

$byACount = [];
foreach ($results as $row) {
    $aCount = substr_count($row['path'], 'A');
    $byACount[$aCount][] = $row['score'];
}

ksort($byACount);
$aCountStats = [];

foreach ($byACount as $aCount => $scores) {
    $mean = array_sum($scores) / count($scores);
    $aCountStats[(string) $aCount] = [
        'runs' => count($scores),
        'min' => min($scores),
        'max' => max($scores),
        'mean' => number_format($mean, 2, '.', ''),
    ];
    printf(
        "  %d A's  runs=%4d  min=%2d  max=%2d  mean=%s\n",
        $aCount,
        count($scores),
        min($scores),
        max($scores),
        number_format($mean, 2)
    );
}

$alwaysA = $aCountStats['3']['mean'] ?? '0.00';
$neverA = $aCountStats['0']['mean'] ?? '0.00';
$spread = (float) $alwaysA - (float) $neverA;
printf("  spread between always-A and never-A: %s points\n", number_format($spread, 2));

E::check(
    'the A-count analysis covers all four buckets',
    count($aCountStats) === 4,
    (string) count($aCountStats)
);

echo "\n== Artifact ==\n";
$reportDir = $root . '/reports';

if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Cannot create reports directory\n");
    exit(1);
}

$artifact = [
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'difficulty' => $requestedDifficulty,
    'board_field_count' => count($boardFields),
    'ruleset_version' => (string) $policy['ruleset_version'],
    'policy_sha256' => (string) $policy['policy_sha256'],
    'regulatory_bundle_sha256' => (string) $regulatory['bundle_sha256'],
    'regulatory_overlay_version' => (string) $regulatory['bundle']['regulatory_overlay_version'],
    'runs' => $count,
    'min' => $min,
    'max' => $max,
    'sum' => $sum,
    'mean_2dp' => number_format($mean, 2, '.', ''),
    'by_appetite' => $appetiteStats,
    'by_scenario' => $scenarioStats,
    'dominance' => $dominance,
    'mean_by_a_count' => $aCountStats,
    'guard_suppressions' => $suppressionCount,
    'governance' => [
        'source_kind' => 'SIMULATION_ASSUMPTION',
        'counsel_reviewed' => false,
        'psychometrically_calibrated' => false,
    ],
];

$target = $reportDir . '/enumeration-' . $policy['ruleset_version'] . '-' . $requestedDifficulty . '.json';
file_put_contents($target, CanonicalJson::encode($artifact));

printf("  %s\n", $target);
printf("  sha256 %s\n", hash('sha256', (string) file_get_contents($target)));

printf("\n%d passed, %d failed\n", E::$passed, E::$failed);
exit(E::$failed === 0 ? 0 : 1);
