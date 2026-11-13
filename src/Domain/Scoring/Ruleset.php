<?php

declare(strict_types=1);

namespace BoardSim\Domain\Scoring;

use RuntimeException;

/**
 * The immutable, versioned rule catalogue.
 */
final class Ruleset
{
    /**
     * The seven structured board-pack fields, in the order fixed by
     * 02_SCORING_MODEL.md and by every pinned scenario's `board.checklists`.
     */
    public const BOARD_RULE_IDS = [
        'DR-041',
        'DR-042',
        'DR-043',
        'DR-044',
        'DR-045',
        'DR-046',
        'DR-047',
    ];

    /**
     * @param array<string, Rule> $rules
     */
    public function __construct(
        public readonly string $version,
        private readonly array $rules,
    ) {
    }

    public function has(string $id): bool
    {
        return isset($this->rules[$id]);
    }

    public function get(string $id): Rule
    {
        if (!isset($this->rules[$id])) {
            throw new RuntimeException("UnknownRuleId: '{$id}' is not in the ruleset {$this->version}");
        }

        return $this->rules[$id];
    }

    /**
     * @return array<string, Rule>
     */
    public function all(): array
    {
        return $this->rules;
    }

    public function count(): int
    {
        return count($this->rules);
    }
}
