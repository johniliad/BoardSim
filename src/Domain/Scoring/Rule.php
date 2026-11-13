<?php

declare(strict_types=1);

namespace BoardSim\Domain\Scoring;

/**
 * One editable decision rule from 03_DECISION_RULES.md.
 *
 * Deltas are integers over the closed ten-dimension set. A zero delta is never
 * authored: absence from the map means "no effect on that dimension", which keeps
 * the source file readable while the trace still records every dimension.
 */
final class Rule
{
    /**
     * @param array<string, int> $deltas dimension code => integer delta
     * @param list<string> $evidenceRefs
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly array $deltas,
        public readonly string $rationale,
        public readonly array $evidenceRefs,
        public readonly string $sourceKind,
    ) {
    }

    public function deltaFor(string $dimension): int
    {
        return $this->deltas[$dimension] ?? 0;
    }

    public function isBoardRule(): bool
    {
        return in_array($this->id, Ruleset::BOARD_RULE_IDS, true);
    }
}
