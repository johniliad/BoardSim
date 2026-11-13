<?php

declare(strict_types=1);

namespace BoardSim\Domain\Regulation;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The deterministic six-step evaluation from 04_REGULATORY_REGISTER.md §6.
 *
 * Order is normative and must not be rearranged:
 *   1. non-binding frameworks never enter the legal status enum;
 *   2. unverified national acts and post-2026 consolidations are hard-capped at
 *      REQUIRES_HUMAN_REVIEW until counsel signs off;
 *   3. an instrument whose effective date has not been reached is NOT_APPLICABLE;
 *   4. scope predicate in Kleene three-valued logic;
 *   5. trigger predicate in Kleene three-valued logic;
 *   6. only TRIGGERED computes statutory clocks.
 *
 * No step may consult a player's chosen option. Applicability derives from
 * authored facts alone (01_ARCHITECTURE.md "No Behavioral Inference").
 */
final class RegulatoryEvaluator
{
    public function __construct(private readonly ClockEngine $clocks = new ClockEngine())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Instrument $instrument, FactSet $facts, string $simulationDate): array
    {
        $base = [
            'instrument' => $instrument->id,
            'title' => $instrument->title,
            'source_url' => $instrument->sourceUrl,
            'nature' => $instrument->nature,
            'verification_tier' => $instrument->verificationTier,
            'reporting_duty' => $instrument->reportingDuty,
            'counsel_signed_off' => $instrument->counselSignedOff,
            'source_kind' => $instrument->sourceKind,
            'timers' => [],
            'evidence_fact_ids' => [],
        ];

        // Step 1: guidance is displayed, never adjudicated.
        if ($instrument->isGuidance()) {
            return array_merge($base, [
                'status' => RegulatoryStatus::GuidanceNotLaw,
                'reason' => 'Non-binding framework or technical guidance; it creates no statutory deadline.',
            ]);
        }

        // Step 2: counsel gate for national acts and post-2026 consolidations.
        if ($instrument->requiresCounselGate() && !$instrument->counselSignedOff) {
            return array_merge($base, [
                'status' => RegulatoryStatus::RequiresHumanReview,
                'reason' => 'National transposition or post-2026 consolidated text requires legal counsel '
                    . 'sign-off before automated predicate triggering.',
            ]);
        }

        // Step 3: effective date.
        $effectiveFrom = $instrument->effectiveFromDate;
        if ($effectiveFrom !== null && $effectiveFrom !== '') {
            $simulated = new DateTimeImmutable($simulationDate, new DateTimeZone('UTC'));
            $effective = new DateTimeImmutable($effectiveFrom, new DateTimeZone('UTC'));

            if ($simulated < $effective) {
                return array_merge($base, [
                    'status' => RegulatoryStatus::NotApplicable,
                    'reason' => sprintf(
                        'Simulation date %s precedes the instrument effective date %s.',
                        $simulated->format('Y-m-d'),
                        $effective->format('Y-m-d')
                    ),
                ]);
            }
        }

        // Step 4: scope.
        $scopeExpr = $instrument->scopeExpr;
        if ($scopeExpr === null) {
            return array_merge($base, [
                'status' => RegulatoryStatus::RequiresHumanReview,
                'reason' => 'Instrument declares no scope predicate.',
            ]);
        }

        $scope = $scopeExpr->evaluate($facts->tri(...));

        if ($scope === TriBool::No) {
            return array_merge($base, [
                'status' => RegulatoryStatus::NotApplicable,
                'reason' => 'Entity, role or product is out of scope on the facts visible at this stage.',
            ]);
        }

        if ($scope === TriBool::Unknown) {
            return array_merge($base, [
                'status' => RegulatoryStatus::PendingClassification,
                'reason' => 'Scope is not established on the facts visible at this stage; no legal clock may start.',
            ]);
        }

        // Step 5: trigger.
        $triggerExpr = $instrument->triggerExpr;
        if ($triggerExpr === null) {
            return array_merge($base, [
                'status' => RegulatoryStatus::RequiresHumanReview,
                'reason' => 'Instrument declares no trigger predicate.',
            ]);
        }

        $trigger = $triggerExpr->evaluate($facts->tri(...));

        if ($trigger === TriBool::No) {
            return array_merge($base, [
                'status' => RegulatoryStatus::ApplicableNotTriggered,
                'reason' => 'In scope, but the statutory trigger conditions are not met on the visible facts.',
            ]);
        }

        if ($trigger === TriBool::Unknown) {
            return array_merge($base, [
                'status' => RegulatoryStatus::PendingClassification,
                'reason' => 'In scope, but incident materiality or the awareness timestamp is unconfirmed.',
            ]);
        }

        // Step 6: TRIGGERED, so clocks may be derived from authored timestamps.
        return array_merge($base, [
            'status' => RegulatoryStatus::Triggered,
            'reason' => 'All scope, materiality and awareness predicates are satisfied by authored facts.',
            'timers' => $this->clocks->compute($instrument->timers, $facts),
            'evidence_fact_ids' => $facts->visibleFactIds(),
        ]);
    }
}
