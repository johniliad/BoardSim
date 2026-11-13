<?php

declare(strict_types=1);

namespace BoardSim\Domain\Regulation;

use RuntimeException;

/**
 * Resolves the guard on a rule that may only fire under a confirmed legal trigger.
 *
 * 03_DECISION_RULES.md: "DR-027 requires a separate regulatory_status=TRIGGERED
 * guard in a stage mapping; it must never be used for mere suspicion."
 *
 * Two conditions must BOTH hold before such a rule is allowed to apply:
 *   1. every symbolic guard named by the pinned scenario is bound to a concrete
 *      instrument and that instrument currently evaluates to the expected status;
 *   2. the aggregate requirement is met — at least one instrument carrying a
 *      reporting duty is TRIGGERED. Standing governance duties (NIS2 Art. 21,
 *      GDPR Art. 32, DORA Art. 5/6/28) never satisfy this, because they are
 *      TRIGGERED simply by being in scope.
 *
 * When the guard is unmet the default action is SUPPRESS_AND_RECORD: the rule
 * contributes no delta and the suppression is written into the trace. Rule
 * substitution is deliberately not implemented — engine/RULE_SCHEMA.md forbids
 * hidden fallback rules.
 */
final class GuardResolver
{
    /**
     * @param array<string, array<string, mixed>> $ruleDefinitions
     * @param array<string, array<string, array<string, list<array<string, mixed>>>>> $bindings
     * @param list<string> $reportingInstrumentIds
     */
    public function __construct(
        private readonly array $ruleDefinitions,
        private readonly array $bindings,
        private readonly array $reportingInstrumentIds,
    ) {
    }

    public function guardIsMandatory(string $ruleId): bool
    {
        $definition = $this->ruleDefinitions[$ruleId] ?? null;

        return $definition !== null && ($definition['fail_closed_on_missing_guard'] ?? false) === true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bindingsFor(string $scenarioId, string $stage, string $ruleId): array
    {
        return $this->bindings[$scenarioId][$stage][$ruleId] ?? [];
    }

    /**
     * The aggregate half of the guard: at least one instrument that carries a
     * reporting duty must be TRIGGERED. Standing governance duties cannot satisfy
     * this, which is what keeps DR-027 from firing on mere suspicion.
     *
     * @param array<string, RegulatoryStatus> $statuses instrument id => status
     */
    public function anyReportingTriggered(array $statuses): bool
    {
        foreach ($this->reportingInstrumentIds as $instrumentId) {
            if (($statuses[$instrumentId] ?? null) === RegulatoryStatus::Triggered) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, RegulatoryStatus> $statuses instrument id => status
     *
     * @return array<string, mixed>
     */
    public function resolve(string $scenarioId, string $stage, string $ruleId, array $statuses): array
    {
        $bindings = $this->bindingsFor($scenarioId, $stage, $ruleId);

        if ($bindings === [] && $this->guardIsMandatory($ruleId)) {
            throw new RuntimeException(
                "GuardMissing: {$ruleId} is used by {$scenarioId}/{$stage} but no guard binding exists"
            );
        }

        if ($bindings === []) {
            return [
                'rule' => $ruleId,
                'guarded' => false,
                'satisfied' => true,
                'suppressed' => false,
                'reason' => 'Rule carries no regulatory guard.',
                'checks' => [],
            ];
        }

        $checks = [];
        $allOk = true;

        foreach ($bindings as $binding) {
            $instrumentId = (string) ($binding['instrument'] ?? '');
            $expected = RegulatoryStatus::from((string) ($binding['expected_status'] ?? 'TRIGGERED'));
            $actual = $statuses[$instrumentId] ?? null;

            $ok = $actual === $expected;
            $allOk = $allOk && $ok;

            $checks[] = [
                'guard_ref' => (string) ($binding['guard_ref'] ?? ''),
                'instrument' => $instrumentId,
                'expected_status' => $expected->value,
                'actual_status' => $actual?->value,
                'satisfied' => $ok,
                'counsel_signed_off' => (bool) ($binding['counsel_signed_off'] ?? false),
                'source_kind' => (string) ($binding['source_kind'] ?? ''),
            ];
        }

        $aggregate = $this->anyReportingTriggered($statuses);

        $satisfied = $allOk && $aggregate;
        $definition = $this->ruleDefinitions[$ruleId] ?? [];
        $onUnmet = (string) ($definition['on_guard_unmet'] ?? 'SUPPRESS_AND_RECORD');

        return [
            'rule' => $ruleId,
            'guarded' => true,
            'satisfied' => $satisfied,
            'suppressed' => !$satisfied && $onUnmet === 'SUPPRESS_AND_RECORD',
            'any_reporting_instrument_triggered' => $aggregate,
            'reason' => $satisfied
                ? 'Confirmed legal trigger established on authored facts.'
                : 'No reporting-duty instrument is TRIGGERED at this stage; the rule is suppressed and '
                    . 'recorded rather than fired.',
            'checks' => $checks,
        ];
    }
}
