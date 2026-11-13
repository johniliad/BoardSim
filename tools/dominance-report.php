<?php

declare(strict_types=1);

/**
 * Authoring aid: list every stage where one option dominates, with the option
 * labels and the rules each option maps to, so remaps can be judged on content
 * rather than on numbers alone.
 *
 * Usage: php tools/dominance-report.php [rulesetVersion]
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;
use BoardSim\Domain\Regulation\FactSet;
use BoardSim\Domain\Regulation\GuardResolver;
use BoardSim\Domain\Regulation\RegulatoryEvaluator;
use BoardSim\Domain\Scoring\FiringBuilder;
use BoardSim\Domain\Scoring\OptionLookup;

$root = dirname(__DIR__);
$policy = (new ScoringPolicyLoader($root))->load($argv[1] ?? null);
$pinned = (new PinnedScenarios($root))->loadAll();
$regulatory = (new OverlayLoader($root))->load();

$lookup = new OptionLookup($pinned);
$builder = new FiringBuilder(
    $lookup,
    $policy['ruleset'],
    new GuardResolver(
        $regulatory['rule_definitions'],
        $regulatory['guard_bindings'],
        $regulatory['reporting_instrument_ids']
    )
);
$evaluator = new RegulatoryEvaluator();
$calculator = $policy['calculator'];

$statusCache = [];
$statusesFor = static function (string $scenarioId, string $stage) use (
    &$statusCache,
    $regulatory,
    $evaluator
): array {
    $key = $scenarioId . '|' . $stage;

    if (isset($statusCache[$key])) {
        return $statusCache[$key];
    }

    $facts = FactSet::fromAuthored($regulatory['scenario_facts'][$scenarioId]['facts'], $stage);
    $statuses = [];

    foreach ($regulatory['instruments'] as $id => $instrument) {
        $statuses[$id] = $evaluator->evaluate($instrument, $facts, '2026-11-12')['status'];
    }

    return $statusCache[$key] = $statuses;
};

$boardFull = [];
$boardNone = [];
foreach ($policy['board_fields'] as $field => $definition) {
    foreach ($definition['options'] as $option) {
        if (($option['substantiated'] ?? false) === true) {
            $boardFull[$field] = (string) $option['id'];
        } else {
            if (!isset($boardNone[$field])) {
                $boardNone[$field] = (string) $option['id'];
            }
        }
    }
}

$optionScores = [];

foreach (array_keys($pinned) as $scenarioId) {
    foreach (['A', 'B', 'C'] as $one) {
        foreach (['A', 'B', 'C'] as $two) {
            foreach (['A', 'B', 'C'] as $three) {
                $decisions = ['S1' => $one, 'S2' => $two, 'S3' => $three];

                foreach (['conservative', 'balanced', 'aggressive'] as $appetite) {
                    foreach ([$boardFull, $boardNone] as $board) {
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
                        $boardResult = $builder->forBoard($board, $policy['board_fields']);
                        $firings = array_merge($firings, $boardResult['firings']);
                        $score = $calculator->calculate($firings, $appetite)['score'];

                        foreach ($decisions as $stage => $optionId) {
                            $optionScores[$scenarioId][$stage][$optionId][] = $score;
                        }
                    }
                }
            }
        }
    }
}

printf("ruleset %s — dominant stages\n\n", $policy['ruleset_version']);

$dominant = 0;

foreach ($optionScores as $scenarioId => $stages) {
    foreach ($stages as $stage => $perOption) {
        $means = [];
        foreach ($perOption as $optionId => $scores) {
            $means[$optionId] = array_sum($scores) / count($scores);
        }
        arsort($means);
        $ranked = array_keys($means);

        if (count($ranked) < 2 || ($means[$ranked[0]] - $means[$ranked[1]]) < 1.0) {
            continue;
        }

        $dominant++;
        printf(
            "%s %s   winner=%s (+%.2f over %s)\n",
            $scenarioId,
            $stage,
            $ranked[0],
            $means[$ranked[0]] - $means[$ranked[1]],
            $ranked[1]
        );

        foreach ((array) ($pinned[$scenarioId]['stages'][(int) substr($stage, 1) - 1]['options'] ?? []) as $option) {
            printf(
                "    %s  mean %5.2f  rules %-26s %s\n",
                (string) $option['id'],
                $means[(string) $option['id']] ?? 0.0,
                implode(',', (array) ($option['rules'] ?? [])),
                substr((string) ($option['label'] ?? ''), 0, 62)
            );
        }
        echo "\n";
    }
}

printf("total dominant stages: %d of %d\n", $dominant, count($optionScores) * 3);
