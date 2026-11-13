<?php

declare(strict_types=1);

namespace BoardSim\Domain\Regulation;

/**
 * One authored regulatory instrument from the Layer 1 global registry.
 *
 * Provenance fields are mandatory on every entry (F-2 §7 governance contract):
 * the value differs by layer — real statute citations carry
 * source_kind=AUTHORITATIVE_SOURCE, while every authored predicate, fact,
 * formatter and weight carries source_kind=SIMULATION_ASSUMPTION.
 */
final class Instrument
{
    /**
     * @param list<array<string, mixed>> $timers
     * @param list<string> $relatedUrls
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $sourceUrl,
        public readonly string $nature,
        public readonly string $verificationTier,
        public readonly bool $reportingDuty,
        public readonly bool $counselSignedOff,
        public readonly ?PredicateExpression $scopeExpr,
        public readonly ?PredicateExpression $triggerExpr,
        public readonly array $timers,
        public readonly ?string $effectiveFromDate,
        public readonly string $sourceKind,
        public readonly bool $counselReviewed,
        public readonly bool $psychometricallyCalibrated,
        public readonly array $relatedUrls = [],
    ) {
    }

    /**
     * Non-binding material: shown, never used for a statutory deadline.
     */
    public function isGuidance(): bool
    {
        return in_array($this->nature, ['FRAMEWORK', 'GUIDANCE'], true)
            || $this->verificationTier === 'GUIDANCE';
    }

    public function requiresCounselGate(): bool
    {
        return $this->verificationTier === 'REQUIRES_COUNSEL_GATE';
    }
}
