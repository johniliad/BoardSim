<?php

declare(strict_types=1);

/**
 * Re-score report: compares two published rulesets over the same 2,268 runs.
 *
 * 08_CONTENT_GOVERNANCE.md §6: "If policy correction needed, publish a re-score
 * report with explicit old/new versions and consent." This produces the numbers
 * behind docs/rescore-report-1.0.0-to-1.1.0.md.
 *
 * Usage: php bin/rescore.php [fromVersion] [toVersion]
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

$fromVersion = $argv[1] ?? '1.0.0';
$root = dirname(__DIR__);

$regulatory = (new OverlayLoader($root))->load();
$pinned = (new PinnedScenarios($root))->loadAll();
$lookup = new OptionLookup($pinned);
$evaluator = new RegulatoryEvaluator();
$guardResolver = new GuardResolver(
    $regulatory['rule_definitions'],
    $regulatory['guard_bindings'],
    $regulatory['reporting_instrument_ids']
);
$simulationDate = '2026-11-12';

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

/**
 * @return array<string, mixed>
 */
$measure = static function (?string $version) use ($root, $pinned, $lookup, $guardResolver, $statusesFor): array {
    $policy = (new ScoringPolicyLoader($root))->load($version);
    $builder = new FiringBuilder($lookup, $policy['ruleset'], $guardResolver);
    $calculator = $policy['calculator'];

    $board = static function (bool $substantiated) use ($policy): array {
        $selections = [];

        foreach ($policy['board_fields'] as $field => $definition) {
            foreach ($definition['options'] as $option) {
                if (($option['substantiated'] ?? false) === $substantiated) {
                    $selections[$field] = (string) $option['id'];
                    break;
                }
            }
        }

        return $selections;
    };

    $paths = [];
    foreach (['A', 'B', 'C'] as $one) {
        foreach (['A', 'B', 'C'] as $two) {
            foreach (['A', 'B', 'C'] as $three) {
                $paths[] = $one . $two . $three;
            }
        }
    }

    $scores = [];
    $labels = [];
    $byAppetite = [];
    $byACount = [];
    $optionScores = [];

    foreach (array_keys($pinned) as $scenarioId) {
        foreach ($paths as $path) {
            $decisions = ['S1' => $path[0], 'S2' => $path[1], 'S3' => $path[2]];

            foreach (['conservative', 'balanced', 'aggressive'] as $appetite) {
                foreach ([true, false] as $substantiated) {
                    $firings = [];

                    foreach ($decisions as $stage => $optionId) {
                        $decision = $builder->forDecision(
                            $scenarioId,
                            $stage,
                            $optionId,
                            $statusesFor($scenarioId, $stage)
                        );
                        $firings = array_merge($firings, $decision['firings']);
                    }

                    $boardResult = $builder->forBoard($board($substantiated), $policy['board_fields']);
                    $firings = array_merge($firings, $boardResult['firings']);

                    $result = $calculator->calculate($firings, $appetite);
                    $score = (int) $result['score'];

                    $scores[] = $score;
                    $byAppetite[$appetite][] = $score;
                    $byACount[substr_count($path, 'A')][] = $score;

                    $label = (string) $result['label'];
                    $labels[$label] = ($labels[$label] ?? 0) + 1;

                    foreach ($decisions as $stage => $optionId) {
                        $optionScores[$scenarioId][$stage][$optionId][] = $score;
                    }
                }
            }
        }
    }

    $dominance = 0;
    $maxGap = 0.0;

    foreach ($optionScores as $stages) {
        foreach ($stages as $perOption) {
            $means = array_map(static fn (array $s): float => array_sum($s) / count($s), $perOption);
            arsort($means);
            $ranked = array_keys($means);

            if (count($ranked) < 2) {
                continue;
            }

            $gap = $means[$ranked[0]] - $means[$ranked[1]];

            if ($gap >= 1.0) {
                $dominance++;
                $maxGap = max($maxGap, $gap);
            }
        }
    }

    $aMean = static function (int $count) use ($byACount): float {
        return isset($byACount[$count])
            ? array_sum($byACount[$count]) / count($byACount[$count])
            : 0.0;
    };

    $appetiteMeans = [];
    foreach ($byAppetite as $appetite => $values) {
        $appetiteMeans[$appetite] = number_format(array_sum($values) / count($values), 2, '.', '');
    }

    return [
        'ruleset_version' => (string) $policy['ruleset_version'],
        'policy_sha256' => (string) $policy['policy_sha256'],
        'runs' => count($scores),
        'min' => min($scores),
        'max' => max($scores),
        'mean' => number_format(array_sum($scores) / count($scores), 2, '.', ''),
        'mean_by_a_count' => [
            '0' => number_format($aMean(0), 2, '.', ''),
            '1' => number_format($aMean(1), 2, '.', ''),
            '2' => number_format($aMean(2), 2, '.', ''),
            '3' => number_format($aMean(3), 2, '.', ''),
        ],
        'always_a_vs_never_a' => number_format($aMean(3) - $aMean(0), 2, '.', ''),
        'appetite_mean' => $appetiteMeans,
        'appetite_spread' => number_format(
            max(array_map('floatval', $appetiteMeans)) - min(array_map('floatval', $appetiteMeans)),
            2,
            '.',
            ''
        ),
        'dominant_stages' => $dominance,
        'largest_dominance_gap' => number_format($maxGap, 2, '.', ''),
        'labels' => $labels,
        'bands_reached' => count($labels),
    ];
};

$before = $measure($fromVersion);
$after = $measure(null);

$rows = [
    'ruleset_version',
    'runs',
    'min',
    'max',
    'mean',
    'always_a_vs_never_a',
    'appetite_spread',
    'dominant_stages',
    'largest_dominance_gap',
    'bands_reached',
];

printf("%-24s %14s %14s\n", 'metric', $before['ruleset_version'], $after['ruleset_version']);
echo str_repeat('-', 54) . "\n";

foreach ($rows as $row) {
    printf("%-24s %14s %14s\n", $row, (string) $before[$row], (string) $after[$row]);
}

echo "\nmean by number of A choices\n";
foreach ($before['mean_by_a_count'] as $count => $value) {
    printf(
        "  %d A's: %s -> %s\n",
        (int) $count,
        $value,
        $after['mean_by_a_count'][$count]
    );
}

echo "\nlabels under each ruleset\n";
foreach ([$before, $after] as $snapshot) {
    printf("  %s: %s\n", $snapshot['ruleset_version'], json_encode($snapshot['labels']));
}

$report = [
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'from' => $before,
    'to' => $after,
    'governance' => [
        'source_kind' => 'SIMULATION_ASSUMPTION',
        'counsel_reviewed' => false,
        'psychometrically_calibrated' => false,
        'note' => 'Arithmetic re-score only; not a psychometric validation.',
    ],
];

$dir = $root . '/reports';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create reports directory\n");
    exit(1);
}

$target = $dir . '/rescore-' . $before['ruleset_version'] . '-to-' . $after['ruleset_version'] . '.json';
file_put_contents($target, CanonicalJson::encode($report));

printf("\nwrote %s\n", $target);
printf("sha256 %s\n", hash('sha256', (string) file_get_contents($target)));
