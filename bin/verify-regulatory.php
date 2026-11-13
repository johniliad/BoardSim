<?php

declare(strict_types=1);

/**
 * F-2 / F-1 acceptance invariants for BS-003 and BS-013.
 *
 * These are the checks from the corrected acceptance list: status transitions,
 * statutory clocks, guard gating, reporting-duty isolation, determinism and the
 * fail-closed vocabulary/guard guarantees.
 *
 * Usage: php bin/verify-regulatory.php
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\OverlayLoader;
use BoardSim\Content\Yaml;
use BoardSim\Content\YamlError;
use BoardSim\Domain\Regulation\FactSet;
use BoardSim\Domain\Regulation\GuardResolver;
use BoardSim\Domain\Regulation\RegulatoryEvaluator;
use BoardSim\Domain\Regulation\RegulatoryStatus;

final class T
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
$content = (new OverlayLoader($root))->load();
$evaluator = new RegulatoryEvaluator();
$resolver = new GuardResolver(
    $content['rule_definitions'],
    $content['guard_bindings'],
    $content['reporting_instrument_ids']
);
$simulationDate = '2026-11-12';

/**
 * @return array{results: array<string, array<string, mixed>>, statuses: array<string, RegulatoryStatus>}
 */
$evaluateAll = static function (FactSet $facts) use ($content, $evaluator, $simulationDate): array {
    $results = [];
    $statuses = [];

    foreach ($content['instruments'] as $id => $instrument) {
        $result = $evaluator->evaluate($instrument, $facts, $simulationDate);
        $results[$id] = $result;
        $statuses[$id] = $result['status'];
    }

    return ['results' => $results, 'statuses' => $statuses];
};

$factsFor = static function (string $scenarioId, string $stage) use ($content): FactSet {
    return FactSet::fromAuthored($content['scenario_facts'][$scenarioId]['facts'], $stage);
};

$timerById = static function (array $result, string $timerId): ?array {
    foreach ($result['timers'] as $timer) {
        if (($timer['id'] ?? null) === $timerId) {
            return $timer;
        }
    }

    return null;
};

echo "== Structural integrity ==\n";
T::check('28 instruments registered', count($content['instruments']) === 28, (string) count($content['instruments']));
T::check(
    '7 reporting-duty instruments',
    count($content['reporting_instrument_ids']) === 7,
    implode(',', $content['reporting_instrument_ids'])
);
T::check('DR-027 has a rule definition', isset($content['rule_definitions']['DR-027']));
T::check(
    'substitution is forbidden',
    ($content['rule_definitions']['DR-027']['substitution_permitted'] ?? null) === false
);
T::check(
    'DR-027 guard bindings exist at S2 for both guarded scenarios',
    isset($content['guard_bindings']['BS-003']['S2']['DR-027'])
        && isset($content['guard_bindings']['BS-013']['S2']['DR-027'])
);

echo "\n== Guidance is never adjudicated ==\n";
$guidanceFacts = $factsFor('BS-013', 'S3');
$guidance = $evaluateAll($guidanceFacts);
foreach (array_keys($content['instruments']) as $id) {
    if (!str_starts_with($id, 'REG-NIST') && !str_starts_with($id, 'REG-ENISA')) {
        continue;
    }

    $result = $guidance['results'][$id];
    T::check(
        "{$id} is GUIDANCE_NOT_LAW with no timers",
        $result['status'] === RegulatoryStatus::GuidanceNotLaw && $result['timers'] === []
    );
}

echo "\n== Counsel gate holds national and post-2026 texts ==\n";
foreach (['REG-GREECE-5160', 'REG-AIA-14', 'REG-AIA-26', 'REG-AIA-73'] as $id) {
    $result = $guidance['results'][$id];
    T::check(
        "{$id} stays REQUIRES_HUMAN_REVIEW",
        $result['status'] === RegulatoryStatus::RequiresHumanReview && $result['timers'] === []
    );
}

echo "\n== BS-003: unresolved at S1, TRIGGERED at S2 from the inject ==\n";
$bs003 = [];
foreach (['S1', 'S2', 'S3'] as $stage) {
    $bs003[$stage] = $evaluateAll($factsFor('BS-003', $stage));
}

T::check(
    'S1 GDPR-33 PENDING_CLASSIFICATION',
    $bs003['S1']['statuses']['REG-GDPR-33'] === RegulatoryStatus::PendingClassification
);
T::check(
    'S1 starts no statutory timer',
    $bs003['S1']['results']['REG-GDPR-33']['timers'] === []
);
T::check(
    'S2 GDPR-33 TRIGGERED',
    $bs003['S2']['statuses']['REG-GDPR-33'] === RegulatoryStatus::Triggered
);

$gdprTimer = $timerById($bs003['S2']['results']['REG-GDPR-33'], 'GDPR_SA_NOTIFICATION');
T::check(
    'S2 GDPR-33 deadline is awareness + 72h',
    $gdprTimer !== null && $gdprTimer['deadline'] === '2026-11-15T10:30:00Z',
    (string) ($gdprTimer['deadline'] ?? 'missing')
);
T::check(
    'S3 remains TRIGGERED with the same deadline',
    $bs003['S3']['statuses']['REG-GDPR-33'] === RegulatoryStatus::Triggered
        && (($timerById($bs003['S3']['results']['REG-GDPR-33'], 'GDPR_SA_NOTIFICATION')['deadline'] ?? null)
            === '2026-11-15T10:30:00Z')
);
T::check(
    'GDPR-34 stays PENDING (plausible risk is not high risk)',
    $bs003['S2']['statuses']['REG-GDPR-34'] === RegulatoryStatus::PendingClassification
        && $bs003['S3']['statuses']['REG-GDPR-34'] === RegulatoryStatus::PendingClassification
);
T::check(
    'NIS2-23 stays PENDING (entity scope unverified)',
    $bs003['S2']['statuses']['REG-NIS2-23'] === RegulatoryStatus::PendingClassification
        && $bs003['S3']['statuses']['REG-NIS2-23'] === RegulatoryStatus::PendingClassification
);

$bs003GuardS2 = $resolver->resolve('BS-003', 'S2', 'DR-027', $bs003['S2']['statuses']);
T::check('S2 guard permits DR-027', $bs003GuardS2['satisfied'] === true);
T::check('S2 guard is not suppressed', $bs003GuardS2['suppressed'] === false);

$s1Unbound = false;
try {
    $resolver->resolve('BS-003', 'S1', 'DR-027', $bs003['S1']['statuses']);
} catch (Throwable $e) {
    $s1Unbound = str_contains($e->getMessage(), 'GuardMissing');
}
T::check('resolving an unbound stage fails closed rather than defaulting', $s1Unbound);
T::check(
    'S1 has no reporting-duty instrument TRIGGERED',
    $resolver->anyReportingTriggered($bs003['S1']['statuses']) === false
);
T::check(
    'S2 has a reporting-duty instrument TRIGGERED',
    $resolver->anyReportingTriggered($bs003['S2']['statuses']) === true
);

$syntheticS1 = new GuardResolver(
    $content['rule_definitions'],
    ['BS-003' => ['S1' => $content['guard_bindings']['BS-003']['S2']]],
    $content['reporting_instrument_ids']
);
$suppressed = $syntheticS1->resolve('BS-003', 'S1', 'DR-027', $bs003['S1']['statuses']);
T::check(
    'an unmet guard suppresses and records instead of firing',
    $suppressed['satisfied'] === false && $suppressed['suppressed'] === true
);

echo "\n== BS-013: DORA major incident classification at S2 ==\n";
$bs013 = [];
foreach (['S1', 'S2', 'S3'] as $stage) {
    $bs013[$stage] = $evaluateAll($factsFor('BS-013', $stage));
}

T::check(
    'S1 DORA-2025-301 PENDING_CLASSIFICATION',
    $bs013['S1']['statuses']['REG-DORA-2025-301'] === RegulatoryStatus::PendingClassification
);
T::check(
    'S1 starts no statutory timer',
    $bs013['S1']['results']['REG-DORA-2025-301']['timers'] === []
);
T::check(
    'S2 DORA-2025-301 TRIGGERED',
    $bs013['S2']['statuses']['REG-DORA-2025-301'] === RegulatoryStatus::Triggered
);
T::check(
    'S2 DORA-19 TRIGGERED',
    $bs013['S2']['statuses']['REG-DORA-19'] === RegulatoryStatus::Triggered
);

$initial = $timerById($bs013['S2']['results']['REG-DORA-2025-301'], 'DORA_INITIAL_NOTIFICATION');
T::check(
    'DORA initial deadline is classification + 4h',
    $initial !== null && $initial['deadline'] === '2026-11-12T12:30:00Z',
    (string) ($initial['deadline'] ?? 'missing')
);
T::check(
    'DORA late-classification rule not engaged',
    $initial !== null && $initial['late_classification_special_rule'] === false
);

$intermediate = $timerById($bs013['S2']['results']['REG-DORA-2025-301'], 'DORA_INTERMEDIATE_REPORT');
T::check(
    'DORA intermediate is a projection at initial + 72h',
    $intermediate !== null
        && $intermediate['deadline'] === '2026-11-15T12:30:00Z'
        && $intermediate['projection'] === true,
    (string) ($intermediate['deadline'] ?? 'missing')
);

$final = $timerById($bs013['S2']['results']['REG-DORA-2025-301'], 'DORA_FINAL_REPORT');
T::check(
    'DORA final is a one-calendar-month projection',
    $final !== null && $final['deadline'] === '2026-12-15T12:30:00Z' && $final['projection'] === true,
    (string) ($final['deadline'] ?? 'missing')
);

T::check(
    'GDPR-33 for BS-013 stays PENDING (no personal data breach asserted)',
    $bs013['S2']['statuses']['REG-GDPR-33'] === RegulatoryStatus::PendingClassification
);

$bs013GuardS2 = $resolver->resolve('BS-013', 'S2', 'DR-027', $bs013['S2']['statuses']);
T::check('BS-013 S2 guard permits DR-027', $bs013GuardS2['satisfied'] === true);

echo "\n== Reporting-duty isolation (B-14) ==\n";
T::check(
    'DORA-28 is TRIGGERED at S1 but carries no reporting duty',
    $bs013['S1']['statuses']['REG-DORA-28'] === RegulatoryStatus::Triggered
        && $content['instruments']['REG-DORA-28']->reportingDuty === false
);
T::check(
    'a TRIGGERED governance duty does not satisfy the DR-027 guard',
    $resolver->anyReportingTriggered($bs013['S1']['statuses']) === false
);

echo "\n== DORA late-classification special rule ==\n";
$lateFacts = FactSet::fromAuthored([
    ['id' => 'X1', 'key' => 'dora_financial_entity', 'type' => 'tri_bool', 'value' => true,
        'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
    ['id' => 'X2', 'key' => 'dora_major_ict_incident_classified', 'type' => 'tri_bool', 'value' => true,
        'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
    ['id' => 'X3', 'key' => 'classification_timestamp_known', 'type' => 'tri_bool', 'value' => true,
        'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
    ['id' => 'X4', 'key' => 'awareness_time', 'type' => 'timestamp_iso8601',
        'value' => '2026-11-10T00:00:00Z', 'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
    ['id' => 'X5', 'key' => 'classification_time', 'type' => 'timestamp_iso8601',
        'value' => '2026-11-11T06:00:00Z', 'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
], 'S1');

$lateResult = $evaluator->evaluate($content['instruments']['REG-DORA-2025-301'], $lateFacts, $simulationDate);
$lateTimer = $timerById($lateResult, 'DORA_INITIAL_NOTIFICATION');
T::check(
    'classification 30h after awareness engages the special rule',
    $lateTimer !== null
        && $lateTimer['late_classification_special_rule'] === true
        && $lateTimer['deadline'] === '2026-11-11T10:00:00Z',
    (string) ($lateTimer['deadline'] ?? 'missing')
);

echo "\n== CRA effective-date gate ==\n";
$craFacts = FactSet::fromAuthored([
    ['id' => 'C1', 'key' => 'manufacturer_scoped_product', 'type' => 'tri_bool', 'value' => true,
        'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
    ['id' => 'C2', 'key' => 'reportable_event', 'type' => 'tri_bool', 'value' => true,
        'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
    ['id' => 'C3', 'key' => 'awareness_time_known', 'type' => 'tri_bool', 'value' => true,
        'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
    ['id' => 'C4', 'key' => 'awareness_time', 'type' => 'timestamp_iso8601',
        'value' => '2026-09-01T00:00:00Z', 'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
    ['id' => 'C5', 'key' => 'date_at_least_2026_09_11', 'type' => 'tri_bool', 'value' => true,
        'visible_from_stage' => 'S1', 'source_kind' => 'SIMULATION_ASSUMPTION'],
], 'S1');

$craBefore = $evaluator->evaluate($content['instruments']['REG-CRA-14'], $craFacts, '2026-09-10');
$craAfter = $evaluator->evaluate($content['instruments']['REG-CRA-14'], $craFacts, '2026-12-01');

T::check(
    'CRA-14 NOT_APPLICABLE before 2026-09-11',
    $craBefore['status'] === RegulatoryStatus::NotApplicable && $craBefore['timers'] === []
);
T::check(
    'CRA-14 TRIGGERED on or after 2026-09-11',
    $craAfter['status'] === RegulatoryStatus::Triggered
        && count($craAfter['timers']) === 2
);

echo "\n== Fail-closed behaviour ==\n";
$noBindingsResolver = new GuardResolver(
    $content['rule_definitions'],
    [],
    $content['reporting_instrument_ids']
);

$threw = false;
try {
    $noBindingsResolver->resolve('BS-003', 'S2', 'DR-027', $bs003['S2']['statuses']);
} catch (Throwable $e) {
    $threw = str_contains($e->getMessage(), 'GuardMissing');
}
T::check('a missing DR-027 guard binding is rejected, not defaulted', $threw);

$yamlCases = [
    'anchor' => "a: &x\n  b: 1\n",
    'alias' => "a: *x\n",
    'merge' => "a:\n  <<: 1\n",
    'tag' => "a: !php/object foo\n",
];

foreach ($yamlCases as $label => $source) {
    $blocked = false;
    try {
        Yaml::parse($source);
    } catch (YamlError) {
        $blocked = true;
    }
    T::check("YAML {$label} is rejected", $blocked);
}

// Flow collections are supported, because the pinned engine/CONFIG.md uses them.
$flow = Yaml::parse("map: {DR-002: -2, DR-004: 2}\nlist: [a, b, c]\nband: {min: 90, label: Excellent}\n");
T::check(
    'flow mappings and sequences parse as scalars',
    $flow['map'] === ['DR-002' => -2, 'DR-004' => 2]
        && $flow['list'] === ['a', 'b', 'c']
        && $flow['band'] === ['min' => 90, 'label' => 'Excellent']
);

echo "\n== Determinism ==\n";
$first = $evaluateAll($factsFor('BS-013', 'S2'));
$second = $evaluateAll($factsFor('BS-013', 'S2'));
$canonical = static function (array $payload): string {
    $flat = [];
    foreach ($payload['results'] as $id => $result) {
        $flat[$id] = $result['status']->value . '|' . json_encode($result['timers']);
    }
    ksort($flat);

    return (string) json_encode($flat);
};
T::check('two evaluations of the same stage are byte-identical', $canonical($first) === $canonical($second));

echo "\n== Stage progression of the aggregate view ==\n";
$bs003Triggered = [];
foreach (['S1', 'S2', 'S3'] as $stage) {
    $triggered = [];
    foreach ($bs003[$stage]['statuses'] as $id => $status) {
        if ($status === RegulatoryStatus::Triggered) {
            $triggered[] = $id;
        }
    }
    sort($triggered);
    $bs003Triggered[$stage] = $triggered;
    printf("  BS-003 %s TRIGGERED: %s\n", $stage, $triggered === [] ? '(none)' : implode(', ', $triggered));
}
T::check(
    'BS-003 GDPR-33 enters the aggregate only from S2',
    !in_array('REG-GDPR-33', $bs003Triggered['S1'], true)
        && in_array('REG-GDPR-33', $bs003Triggered['S2'], true)
);
T::check(
    'BS-003 NIS2-23 never enters the aggregate',
    !in_array('REG-NIS2-23', $bs003Triggered['S3'], true)
);

echo "\n== Referenced-instrument matrix (the twelve newly authored scenarios) ==\n";
$newlyAuthored = [
    'BS-001', 'BS-002', 'BS-004', 'BS-005', 'BS-006', 'BS-007',
    'BS-008', 'BS-009', 'BS-010', 'BS-011', 'BS-012', 'BS-014',
];

T::check(
    'every pinned scenario has an authored fact set',
    count($content['scenario_facts']) === count($content['pinned_scenarios']),
    count($content['scenario_facts']) . '/' . count($content['pinned_scenarios'])
);

$stageView = [];

foreach ($newlyAuthored as $scenarioId) {
    $refs = $content['pinned_scenarios'][$scenarioId]['regulatory_refs'];

    foreach (['S1', 'S3'] as $stage) {
        $view = $evaluateAll($factsFor($scenarioId, $stage));
        $stageView[$scenarioId][$stage] = $view;

        $cells = [];
        foreach ($refs as $ref) {
            if (str_starts_with($ref, 'REG-NIST') || str_starts_with($ref, 'REG-ENISA')) {
                continue;
            }
            $cells[] = substr($ref, 4) . '=' . $view['statuses'][$ref]->value;
        }

        printf("  %s %s  %s\n", $scenarioId, $stage, implode('  ', $cells));
    }
}

echo "\n== Distinct outcomes worth asserting ==\n";
T::check(
    'BS-010 (retail) resolves NIS2-21 to NOT_APPLICABLE',
    $stageView['BS-010']['S1']['statuses']['REG-NIS2-21'] === RegulatoryStatus::NotApplicable
);
T::check(
    'BS-012 (no incident) resolves NIS2-23 to APPLICABLE_NOT_TRIGGERED',
    $stageView['BS-012']['S3']['statuses']['REG-NIS2-23'] === RegulatoryStatus::ApplicableNotTriggered
);
T::check(
    'BS-012 resolves EU-2690 to APPLICABLE_NOT_TRIGGERED',
    $stageView['BS-012']['S3']['statuses']['REG-EU-2690'] === RegulatoryStatus::ApplicableNotTriggered
);

$bs002s1 = $evaluateAll($factsFor('BS-002', 'S1'));
$bs002s2 = $evaluateAll($factsFor('BS-002', 'S2'));
T::check(
    'BS-002 NIS2-23 goes PENDING at S1 -> TRIGGERED at S2',
    $bs002s1['statuses']['REG-NIS2-23'] === RegulatoryStatus::PendingClassification
        && $bs002s2['statuses']['REG-NIS2-23'] === RegulatoryStatus::Triggered
);

$early = $timerById($bs002s2['results']['REG-NIS2-23'], 'NIS2_EARLY_WARNING');
$notify = $timerById($bs002s2['results']['REG-NIS2-23'], 'NIS2_INCIDENT_NOTIFICATION');
T::check(
    'BS-002 early warning is awareness + 24h',
    $early !== null && $early['deadline'] === '2026-11-13T07:45:00Z',
    (string) ($early['deadline'] ?? 'missing')
);
T::check(
    'BS-002 incident notification is awareness + 72h',
    $notify !== null && $notify['deadline'] === '2026-11-15T07:45:00Z',
    (string) ($notify['deadline'] ?? 'missing')
);

T::check(
    'BS-001 keeps NIS2-23 and GDPR-33 PENDING at every stage',
    $stageView['BS-001']['S1']['statuses']['REG-NIS2-23'] === RegulatoryStatus::PendingClassification
        && $stageView['BS-001']['S3']['statuses']['REG-GDPR-33'] === RegulatoryStatus::PendingClassification
);

T::check(
    'BS-005 (cloud concentration) engages DORA-28',
    $stageView['BS-005']['S1']['statuses']['REG-DORA-28'] === RegulatoryStatus::Triggered
);
T::check(
    'BS-011 engages DORA-5 and GDPR-32',
    $stageView['BS-011']['S1']['statuses']['REG-DORA-5'] === RegulatoryStatus::Triggered
        && $stageView['BS-011']['S1']['statuses']['REG-GDPR-32'] === RegulatoryStatus::Triggered
);

T::check(
    'BS-014 AI Act stays counsel-gated even after high-risk is confirmed',
    $stageView['BS-014']['S3']['statuses']['REG-AIA-14'] === RegulatoryStatus::RequiresHumanReview
        && $stageView['BS-014']['S3']['statuses']['REG-AIA-73'] === RegulatoryStatus::RequiresHumanReview
);
T::check(
    'BS-014 GDPR-22 becomes TRIGGERED once solely-automated is established',
    $stageView['BS-014']['S1']['statuses']['REG-GDPR-22'] === RegulatoryStatus::PendingClassification
        && $stageView['BS-014']['S3']['statuses']['REG-GDPR-22'] === RegulatoryStatus::Triggered
);

exit(T::summary());
