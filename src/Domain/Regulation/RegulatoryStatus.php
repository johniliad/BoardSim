<?php

declare(strict_types=1);

namespace BoardSim\Domain\Regulation;

/**
 * The closed regulatory-status enum from 01_ARCHITECTURE.md.
 *
 * GUIDANCE_NOT_LAW is an explicit extension: 04_REGULATORY_REGISTER.md requires
 * NIST, ENISA and FERMA material to be shown but never to enter the legal
 * status enum, and 07_ACCEPTANCE_TESTS.md asserts they never become TRIGGERED.
 */
enum RegulatoryStatus: string
{
    case NotApplicable = 'NOT_APPLICABLE';
    case ApplicableNotTriggered = 'APPLICABLE_NOT_TRIGGERED';
    case Triggered = 'TRIGGERED';
    case PendingClassification = 'PENDING_CLASSIFICATION';
    case RequiresHumanReview = 'REQUIRES_HUMAN_REVIEW';
    case GuidanceNotLaw = 'GUIDANCE_NOT_LAW';

    /**
     * Only TRIGGERED may start a statutory clock (01_ARCHITECTURE.md).
     */
    public function mayStartLegalTimer(): bool
    {
        return $this === self::Triggered;
    }

    /**
     * Presentation band reusing the existing severity tokens from ThreatPulse.
     */
    public function severityBand(): string
    {
        return match ($this) {
            self::Triggered => 'critical',
            self::RequiresHumanReview => 'high',
            self::PendingClassification => 'medium',
            self::ApplicableNotTriggered => 'low',
            self::NotApplicable => 'none',
            self::GuidanceNotLaw => 'guidance',
        };
    }
}
