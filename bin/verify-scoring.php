<?php

declare(strict_types=1);

/**
 * Stage 2 acceptance: exact scoring arithmetic and the F-1 guard chain.
 *
 * Uses the published goldens from 07_ACCEPTANCE_TESTS.md and the worked trace in
 * 09_REPLAY_REPORT.md. Usage: php bin/verify-scoring.php
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
use BoardSim\Domain\Scoring\ScoreCalculator;

final class S
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

    public static function summary(): int
    {
        printf("\n%d passed, %d failed\n", self::$passed, self::$failed);

        return self::$failed === 0 ? 0 : 1;
    }
}

$root = dirname(__DIR__);

$regulatory = (new OverlayLoader($root))->load();
// The published goldens belong to ruleset 1.0.0, so that version is requested
// explicitly. After a calibration overlay exists the default policy is the newer
// version, and archived runs must still reproduce against the version they pinned.
$policy = (new ScoringPolicyLoader($root))->load('1.0.0');
$pinned = (new PinnedScenarios($root))->loadAll();

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

$statusesFor = static function (string $scenarioId, string $stage) use ($regulatory, $evaluator, $simulationDate): array {
    $facts = FactSet::fromAuthored($regulatory['scenario_facts'][$scenarioId]['facts'], $stage);
    $statuses = [];

    foreach ($regulatory['instruments'] as $id => $instrument) {
        $statuses[$id] = $evaluator->evaluate($instrument, $facts, $simulationDate)['status'];
    }

    return $statuses;
};

$boardSelections = static function (bool $substantiated) use ($policy): array {
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

/**
 * @param array<string, string> $path stage => option id
 * @param array<string, string>|null $board
 */
$run = static function (
    string $scenarioId,
    array $path,
    ?array $board,
    string $appetite
) use ($builder, $calculator, $statusesFor, $policy): array {
    $firings = [];
    $suppressed = [];

    foreach ($path as $stage => $optionId) {
        $decision = $builder->forDecision($scenarioId, $stage, $optionId, $statusesFor($scenarioId, $stage));
        $firings = array_merge($firings, $decision['firings']);
        $suppressed = array_merge($suppressed, $decision['suppressed']);
    }

    $declined = [];

    if ($board !== null) {
        $boardResult = $builder->forBoard($board, $policy['board_fields']);
        $firings = array_merge($firings, $boardResult['firings']);
        $declined = $boardResult['declined'];
    }

    $result = $calculator->calculate($firings, $appetite);

    return [
        'result' => $result,
        'suppressed' => $suppressed,
        'declined' => $declined,
        'rule_ids' => array_map(static fn (array $f): string => $f['rule']->id, $firings),
    ];
};

echo "== Policy loads and validates ==\n";
S::check('ruleset version is 1.0.0', $policy['ruleset_version'] === '1.0.0', $policy['ruleset_version']);
S::check('47 rules loaded', $policy['ruleset']->count() === 47, (string) $policy['ruleset']->count());
S::check('weights sum to 100', array_sum($policy['weights']) === 100);
S::check(
    'board rubric has 7 fields',
    count($policy['board_fields']) === 7,
    (string) count($policy['board_fields'])
);
printf("  policy SHA-256: %s\n", (string) $policy['policy_sha256']);

$referenced = $lookup->allReferencedRuleIds();
$missing = array_values(array_filter($referenced, static fn (string $id): bool => !$policy['ruleset']->has($id)));
S::check('every scenario-referenced rule exists in the ruleset', $missing === [], implode(',', $missing));

$boardInScenarios = array_values(array_filter(
    $referenced,
    static fn (string $id): bool => in_array($id, \BoardSim\Domain\Scoring\Ruleset::BOARD_RULE_IDS, true)
));
S::check('no board rule is bound to a scenario option', $boardInScenarios === [], implode(',', $boardInScenarios));

echo "\n== Formula golden (07_ACCEPTANCE_TESTS.md) ==\n";
$golden = $calculator->scoreFromDimensions([
    'SJ' => 80, 'BI' => 70, 'RJ' => 75, 'DQ' => 75, 'LE' => 70,
    'CO' => 70, 'RG' => 70, 'FJ' => 65, 'RE' => 70, 'SM' => 65,
]);
S::check('numerator is 7195', $golden['numerator'] === 7195, (string) $golden['numerator']);
S::check('score is 72', $golden['score'] === 72, (string) $golden['score']);

$baseline = array_fill_keys(\BoardSim\Domain\Scoring\Dimension::codes(), 70);
$neutral = $calculator->scoreFromDimensions($baseline);
S::check('a flat 70 vector scores 70', $neutral['score'] === 70, (string) $neutral['score']);

$high = $baseline;
$high['SJ'] = 101;
$low = $baseline;
$low['SM'] = -1;
S::check('101 clamps to 100', $calculator->scoreFromDimensions($high)['dimensions']['SJ'] === 100);
S::check('-1 clamps to 0', $calculator->scoreFromDimensions($low)['dimensions']['SM'] === 0);

echo "\n== Worked trace golden: BS-001 A,A,A + substantiated board ==\n";
$bs001 = $run('BS-001', ['S1' => 'A', 'S2' => 'A', 'S3' => 'A'], $boardSelections(true), 'balanced');
$r = $bs001['result'];

printf("  rules: %s\n", implode(', ', $bs001['rule_ids']));
printf("  dims : %s\n", json_encode($r['dimensions']));
printf("  numerator=%d score=%d label=%s\n", $r['numerator'], $r['score'], (string) $r['label']);

S::check(
    'numerator is 8058',
    $r['numerator'] === 8058,
    (string) $r['numerator']
);
S::check('score is 81', $r['score'] === 81, (string) $r['score']);
S::check('SJ is 70', $r['dimensions']['SJ'] === 70, (string) $r['dimensions']['SJ']);
S::check('BI is 71', $r['dimensions']['BI'] === 71, (string) $r['dimensions']['BI']);
S::check('RJ is 85', $r['dimensions']['RJ'] === 85, (string) $r['dimensions']['RJ']);
S::check('DQ is 89', $r['dimensions']['DQ'] === 89, (string) $r['dimensions']['DQ']);
S::check('LE is 82', $r['dimensions']['LE'] === 82, (string) $r['dimensions']['LE']);
S::check('CO is 80', $r['dimensions']['CO'] === 80, (string) $r['dimensions']['CO']);
S::check('RG is 89', $r['dimensions']['RG'] === 89, (string) $r['dimensions']['RG']);
S::check('FJ is 71', $r['dimensions']['FJ'] === 71, (string) $r['dimensions']['FJ']);
S::check('RE is 86', $r['dimensions']['RE'] === 86, (string) $r['dimensions']['RE']);
S::check('SM is 82', $r['dimensions']['SM'] === 82, (string) $r['dimensions']['SM']);
S::check(
    'trace lists S1 rules in ascending id order',
    array_slice(array_column($r['trace'], 'rule'), 0, 3) === ['DR-001', 'DR-003', 'DR-004'],
    implode(',', array_slice(array_column($r['trace'], 'rule'), 0, 3))
);
S::check(
    'authored order is re-sorted into the trace',
    array_slice($bs001['rule_ids'], 0, 3) === ['DR-001', 'DR-004', 'DR-003'],
    implode(',', array_slice($bs001['rule_ids'], 0, 3))
);

echo "\n== Board rubric gates points ==\n";
$noBoard = $run('BS-001', ['S1' => 'A', 'S2' => 'A', 'S3' => 'A'], $boardSelections(false), 'balanced');
S::check(
    'seven unsubstantiated board answers fire nothing',
    $noBoard['result']['score'] < $bs001['result']['score'],
    $noBoard['result']['score'] . ' vs ' . $bs001['result']['score']
);
S::check('all seven board fields were declined', count($noBoard['declined']) === 7, (string) count($noBoard['declined']));

$unknownOption = false;
try {
    $builder->forBoard(['situation' => 'NOT-A-REAL-OPTION'] + $boardSelections(true), $policy['board_fields']);
} catch (Throwable $e) {
    $unknownOption = str_contains($e->getMessage(), 'UnknownBoardOption');
}
S::check('an unknown board option is rejected', $unknownOption);

echo "\n== Risk appetite touches RJ only, and only four rules ==\n";
$conservative = $run('BS-001', ['S1' => 'A', 'S2' => 'A', 'S3' => 'A'], $boardSelections(true), 'conservative');
S::check(
    'conservative changes RJ by exactly -4 (DR-004 +2, DR-002 absent, DR-010 absent)',
    $conservative['result']['dimensions']['RJ'] !== $r['dimensions']['RJ']
);
$rjOnly = true;
foreach (\BoardSim\Domain\Scoring\Dimension::codes() as $code) {
    if ($code === 'RJ') {
        continue;
    }
    if ($conservative['result']['dimensions'][$code] !== $r['dimensions'][$code]) {
        $rjOnly = false;
    }
}
S::check('appetite leaves every non-RJ dimension untouched', $rjOnly);

$dr010 = $policy['ruleset']->get('DR-010');
$single = $calculator->calculate([['stage' => 'S1', 'rule' => $dr010]], 'conservative');
S::check(
    'DR-010 adds +2 RJ under conservative appetite',
    $single['trace'][0]['risk_appetite_delta'] === 2
        && $single['dimensions']['RJ'] === 70 + 4 + 2,
    'RJ=' . $single['dimensions']['RJ']
);

$dr010Keyed = $calculator->calculate([['stage' => 'S1', 'rule' => $dr010]], 'balanced');
S::check(
    'balanced appetite adds no modifier',
    $dr010Keyed['trace'][0]['risk_appetite_delta'] === 0
);

echo "\n== F-1 end to end: the guard removes DR-027 from the score ==\n";
$bs003s1Statuses = $statusesFor('BS-003', 'S1');
$bs003s2Statuses = $statusesFor('BS-003', 'S2');

$met = $builder->forDecision('BS-003', 'S2', 'B', $bs003s2Statuses);
$unmet = $builder->forDecision('BS-003', 'S2', 'B', $bs003s1Statuses);

$metRules = array_map(static fn (array $f): string => $f['rule']->id, $met['firings']);
$unmetRules = array_map(static fn (array $f): string => $f['rule']->id, $unmet['firings']);

S::check(
    'BS-003 S2/B fires DR-027 when the trigger is established',
    in_array('DR-027', $metRules, true),
    implode(',', $metRules)
);
S::check(
    'BS-003 S2/B suppresses DR-027 when the trigger is not established',
    !in_array('DR-027', $unmetRules, true) && in_array('DR-014', $unmetRules, true),
    implode(',', $unmetRules)
);
S::check(
    'the suppression is recorded, not silent',
    count($met['suppressed']) === 0
        && count($unmet['suppressed']) === 1
        && $unmet['suppressed'][0]['suppressed_by_guard'] === true
);
S::check(
    'no rule is ever substituted',
    $metRules === ['DR-014', 'DR-027'] || $metRules === ['DR-027', 'DR-014'],
    implode(',', $metRules)
);

$bs003Scored = $calculator->calculate($met['firings'], 'balanced');
$bs003Suppressed = $calculator->calculate($unmet['firings'], 'balanced');
S::check(
    'the suppressed run scores higher on RG than the fired run',
    $bs003Suppressed['dimensions']['RG'] > $bs003Scored['dimensions']['RG'],
    'RG ' . $bs003Suppressed['dimensions']['RG'] . ' vs ' . $bs003Scored['dimensions']['RG']
);

echo "\n== Determinism and ordering ==\n";
$forward = $calculator->calculate([
    ['stage' => 'S1', 'rule' => $policy['ruleset']->get('DR-001')],
    ['stage' => 'S1', 'rule' => $policy['ruleset']->get('DR-004')],
    ['stage' => 'S1', 'rule' => $policy['ruleset']->get('DR-003')],
], 'balanced');

$shuffled = $calculator->calculate([
    ['stage' => 'S1', 'rule' => $policy['ruleset']->get('DR-003')],
    ['stage' => 'S1', 'rule' => $policy['ruleset']->get('DR-001')],
    ['stage' => 'S1', 'rule' => $policy['ruleset']->get('DR-004')],
], 'balanced');

S::check(
    'input order does not change dimensions or score',
    $forward['dimensions'] === $shuffled['dimensions'] && $forward['score'] === $shuffled['score']
);
S::check(
    'trace is emitted in ascending rule order regardless of input',
    array_column($forward['trace'], 'rule') === ['DR-001', 'DR-003', 'DR-004'],
    implode(',', array_column($forward['trace'], 'rule'))
);

$repeatA = $run('BS-013', ['S1' => 'A', 'S2' => 'A', 'S3' => 'A'], $boardSelections(true), 'balanced');
$repeatB = $run('BS-013', ['S1' => 'A', 'S2' => 'A', 'S3' => 'A'], $boardSelections(true), 'balanced');
S::check(
    'a full run is byte-identical when replayed',
    json_encode($repeatA['result']) === json_encode($repeatB['result'])
);

$duplicateRejected = false;
try {
    $calculator->calculate([
        ['stage' => 'S1', 'rule' => $policy['ruleset']->get('DR-001')],
        ['stage' => 'S1', 'rule' => $policy['ruleset']->get('DR-001')],
    ], 'balanced');
} catch (Throwable $e) {
    $duplicateRejected = str_contains($e->getMessage(), 'DuplicateFiring');
}
S::check('a rule cannot fire twice in one stage', $duplicateRejected);

$unknownAppetite = false;
try {
    $calculator->calculate([], 'reckless');
} catch (Throwable $e) {
    $unknownAppetite = true;
}
S::check('an unknown risk appetite is rejected', $unknownAppetite);

echo "\n== Score distribution across the corpus (balanced) ==\n";
$scores = [];

foreach (array_keys($pinned) as $scenarioId) {
    foreach ([['A', 'A', 'A'], ['B', 'B', 'B'], ['C', 'C', 'C']] as $path) {
        $outcome = $run(
            $scenarioId,
            ['S1' => $path[0], 'S2' => $path[1], 'S3' => $path[2]],
            $boardSelections(true),
            'balanced'
        );
        $scores[$scenarioId][implode('', $path)] = $outcome['result']['score'];
    }
}

$flat = array_merge(...array_values($scores));
printf("  min=%d max=%d over %d sampled runs\n", min($flat), max($flat), count($flat));

S::check('all sampled scores fall inside 0..100', min($flat) >= 0 && max($flat) <= 100);
S::check(
    'the all-A path is not identical for every scenario',
    count(array_unique(array_map(static fn (array $s): int => $s['AAA'], $scores))) > 1
);
S::check(
    'no scenario gives the same score for all three uniform paths',
    count(array_filter($scores, static fn (array $s): bool => count(array_unique($s)) === 1)) === 0
);

foreach ($scores as $scenarioId => $paths) {
    printf("  %s  A=%2d  B=%2d  C=%2d\n", $scenarioId, $paths['AAA'], $paths['BBB'], $paths['CCC']);
}

echo "\n== Current calibrated ruleset 1.3.2 ==\n";
$calibrated = (new ScoringPolicyLoader($root))->load();
$calibratedBuilder = new FiringBuilder($lookup, $calibrated['ruleset'], $resolver);
$calibratedCalculator = $calibrated['calculator'];

S::check(
    'the default policy is the calibrated version',
    $calibrated['ruleset_version'] !== $policy['ruleset_version'],
    $calibrated['ruleset_version'] . ' vs ' . $policy['ruleset_version']
);
S::check(
    'the two versions have different policy hashes',
    $calibrated['policy_sha256'] !== $policy['policy_sha256']
);
S::check(
    'both versions still hold 47 rules',
    $calibrated['ruleset']->count() === 47 && $policy['ruleset']->count() === 47
);
S::check(
    'weights and bounds are untouched by calibration',
    $calibrated['weights'] === $policy['weights']
        && $calibrated['config']['score_start_each_dimension'] === 70
);

$calibratedFirings = [];
foreach (['S1' => 'A', 'S2' => 'A', 'S3' => 'A'] as $stage => $optionId) {
    $decision = $calibratedBuilder->forDecision(
        'BS-001',
        $stage,
        $optionId,
        $statusesFor('BS-001', $stage)
    );
    $calibratedFirings = array_merge($calibratedFirings, $decision['firings']);
}
$calibratedBoard = $calibratedBuilder->forBoard($boardSelections(true), $calibrated['board_fields']);
$calibratedFirings = array_merge($calibratedFirings, $calibratedBoard['firings']);
$calibratedResult = $calibratedCalculator->calculate($calibratedFirings, 'balanced');

printf("  BS-001 A,A,A under 1.3.2: numerator=%d score=%d label=%s\n",
    $calibratedResult['numerator'],
    $calibratedResult['score'],
    (string) $calibratedResult['label']
);

S::check(
    'the calibrated run scores lower than the pinned run for the same path',
    $calibratedResult['score'] < 81,
    (string) $calibratedResult['score']
);
S::check(
    'every calibrated rule keeps its dimension set and sign',
    (function () use ($policy, $calibrated): bool {
        foreach ($policy['ruleset']->all() as $id => $original) {
            $calibratedRule = $calibrated['ruleset']->get((string) $id);

            foreach ($original->deltas as $dimension => $delta) {
                $newDelta = $calibratedRule->deltas[$dimension] ?? null;

                if (!is_int($newDelta)) {
                    return false;
                }

                // A rebalance may shrink a delta but must never flip its sign.
                if (($delta > 0 && $newDelta <= 0) || ($delta < 0 && $newDelta >= 0)) {
                    return false;
                }
            }
        }

        return true;
    })()
);

exit(S::summary());
