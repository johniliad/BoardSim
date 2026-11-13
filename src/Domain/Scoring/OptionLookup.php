<?php

declare(strict_types=1);

namespace BoardSim\Domain\Scoring;

use RuntimeException;

/**
 * Read-only access to the authored option graph of a pinned scenario.
 *
 * Every lookup fails closed: an unknown scenario, stage or option is an error,
 * never an empty list, because a silently missing option would drop its rules
 * from the score without any trace entry.
 */
final class OptionLookup
{
    /**
     * @param array<string, array<string, mixed>> $pinnedScenarios
     */
    public function __construct(private readonly array $pinnedScenarios)
    {
    }

    /**
     * @return list<string>
     */
    public function stageIds(string $scenarioId): array
    {
        $ids = [];

        foreach ($this->stages($scenarioId) as $stage) {
            $ids[] = (string) ($stage['id'] ?? '');
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public function option(string $scenarioId, string $stageId, string $optionId): array
    {
        foreach ($this->stages($scenarioId) as $stage) {
            if ((string) ($stage['id'] ?? '') !== $stageId) {
                continue;
            }

            foreach ((array) ($stage['options'] ?? []) as $option) {
                if ((string) ($option['id'] ?? '') === $optionId) {
                    /** @var array<string, mixed> $option */
                    return $option;
                }
            }

            throw new RuntimeException(
                "UnknownOption: {$scenarioId}/{$stageId} has no option '{$optionId}'"
            );
        }

        throw new RuntimeException("UnknownStage: {$scenarioId} has no stage '{$stageId}'");
    }

    /**
     * @return list<string>
     */
    public function ruleIds(string $scenarioId, string $stageId, string $optionId): array
    {
        $rules = $this->option($scenarioId, $stageId, $optionId)['rules'] ?? [];

        if (!is_array($rules)) {
            throw new RuntimeException("Option {$scenarioId}/{$stageId}/{$optionId} has malformed rules");
        }

        return array_values(array_map('strval', $rules));
    }

    /**
     * Every rule id referenced by any option in the corpus.
     *
     * @return list<string>
     */
    public function allReferencedRuleIds(): array
    {
        $ids = [];

        foreach (array_keys($this->pinnedScenarios) as $scenarioId) {
            foreach ($this->stages($scenarioId) as $stage) {
                foreach ((array) ($stage['options'] ?? []) as $option) {
                    foreach ((array) ($option['rules'] ?? []) as $ruleId) {
                        $ids[(string) $ruleId] = true;
                    }
                }
            }
        }

        $sorted = array_keys($ids);
        sort($sorted, SORT_STRING);

        return $sorted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stages(string $scenarioId): array
    {
        if (!isset($this->pinnedScenarios[$scenarioId])) {
            throw new RuntimeException("UnknownScenario: '{$scenarioId}'");
        }

        $stages = $this->pinnedScenarios[$scenarioId]['stages'] ?? [];

        if (!is_array($stages)) {
            throw new RuntimeException("Scenario {$scenarioId} has malformed stages");
        }

        /** @var list<array<string, mixed>> $stages */
        return array_values($stages);
    }
}
