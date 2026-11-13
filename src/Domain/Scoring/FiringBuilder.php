<?php

declare(strict_types=1);

namespace BoardSim\Domain\Scoring;

use BoardSim\Domain\Regulation\GuardResolver;
use BoardSim\Domain\Regulation\RegulatoryStatus;
use RuntimeException;

/**
 * Turns a structured decision into the exact set of rule firings.
 *
 * This is the single place where a regulatory guard can remove a rule from a
 * score. A suppressed rule contributes nothing and is recorded, rather than being
 * replaced by another rule (engine/RULE_SCHEMA.md forbids hidden fallbacks).
 */
final class FiringBuilder
{
    public function __construct(
        private readonly OptionLookup $options,
        private readonly Ruleset $ruleset,
        private readonly GuardResolver $guards,
    ) {
    }

    /**
     * @param array<string, RegulatoryStatus> $statuses instrument id => status at this stage
     *
     * @return array{
     *     firings: list<array{stage: string, rule: Rule}>,
     *     suppressed: list<array<string, mixed>>,
     *     guard_evidence: list<array<string, mixed>>
     * }
     */
    public function forDecision(
        string $scenarioId,
        string $stageId,
        string $optionId,
        array $statuses
    ): array {
        $firings = [];
        $suppressed = [];
        $guardEvidence = [];

        foreach ($this->options->ruleIds($scenarioId, $stageId, $optionId) as $ruleId) {
            $rule = $this->ruleset->get($ruleId);
            $guard = $this->guards->resolve($scenarioId, $stageId, $ruleId, $statuses);

            if ($guard['guarded'] === true && $guard['satisfied'] !== true) {
                $suppressed[] = [
                    'stage' => $stageId,
                    'rule' => $ruleId,
                    'suppressed_by_guard' => true,
                    'reason' => (string) $guard['reason'],
                    'checks' => $guard['checks'],
                ];

                continue;
            }

            if ($guard['guarded'] === true) {
                $guardEvidence[] = [
                    'stage' => $stageId,
                    'rule' => $ruleId,
                    'checks' => $guard['checks'],
                ];
            }

            $firings[] = ['stage' => $stageId, 'rule' => $rule];
        }

        return [
            'firings' => $firings,
            'suppressed' => $suppressed,
            'guard_evidence' => $guardEvidence,
        ];
    }

    /**
     * Board-pack checklists: a rule fires only when the selected structured
     * option is authored as substantiated. Narrative prose never authorises a
     * point (02_SCORING_MODEL.md).
     *
     * @param array<string, string> $selections field => option id
     * @param array<string, array<string, mixed>> $fields authored checklist fields
     *
     * @return array{firings: list<array{stage: string, rule: Rule}>, declined: list<array<string, mixed>>}
     */
    public function forBoard(array $selections, array $fields): array
    {
        $firings = [];
        $declined = [];

        foreach ($fields as $field => $definition) {
            $selected = $selections[$field] ?? null;

            if (!is_string($selected) || $selected === '') {
                throw new RuntimeException("BoardFieldMissing: no option selected for '{$field}'");
            }

            $ruleId = (string) ($definition['rule'] ?? '');
            $rule = $this->ruleset->get($ruleId);

            $chosen = null;
            foreach ((array) ($definition['options'] ?? []) as $option) {
                if ((string) ($option['id'] ?? '') === $selected) {
                    $chosen = $option;
                    break;
                }
            }

            if ($chosen === null) {
                throw new RuntimeException("UnknownBoardOption: '{$selected}' for field '{$field}'");
            }

            if (($chosen['substantiated'] ?? false) === true) {
                $firings[] = ['stage' => 'BOARD', 'rule' => $rule];

                continue;
            }

            $declined[] = [
                'field' => $field,
                'rule' => $ruleId,
                'option' => $selected,
                'reason' => (string) ($chosen['reason'] ?? 'not substantiated'),
            ];
        }

        return ['firings' => $firings, 'declined' => $declined];
    }
}
