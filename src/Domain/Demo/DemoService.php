<?php

declare(strict_types=1);

namespace BoardSim\Domain\Demo;

use BoardSim\Content\DemoScenario;
use BoardSim\Domain\Run\DomainError;
use BoardSim\Domain\Scoring\Ruleset;

/**
 * The public demonstration.
 *
 * Three hard properties, each structural rather than a promise in prose:
 *
 *   1. It cannot produce a score, because the scoring calculator is never
 *      constructed here. There is no code path from a demo decision to a total.
 *   2. It cannot persist anything, because it holds no store.
 *   3. It is stateless, so the client sends the stage and choice and gets one
 *      stage's mechanism back. Nothing accumulates, so there is nothing to keep.
 *
 * What it shows: which authored rules a choice maps to, and the dimension movement
 * each rule applies. What it does not show: guard evaluation, clamping, appetite
 * or any aggregate. The demo options use unguarded rules only, and
 * `bin/verify-api.php` asserts that no demo rule is guard-bound, so the display
 * cannot overstate what a scored run would do.
 */
final class DemoService
{
    public function __construct(
        private readonly Ruleset $ruleset,
        private readonly string $rulesetVersion,
        private readonly DemoScenario $scenario,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function start(): array
    {
        $this->requireAvailable();
        $scenario = $this->scenario->all();
        $stages = [];

        foreach ((array) $scenario['stages'] as $stage) {
            $options = [];

            foreach ((array) $stage['options'] as $option) {
                // Labels only. Rule ids are withheld exactly as they are during a
                // scored run, so the demo teaches the interface, not the rubric.
                $options[] = [
                    'id' => (string) $option['id'],
                    'label' => (string) $option['label'],
                ];
            }

            $stages[] = [
                'stage_id' => (string) $stage['id'],
                'time' => (string) ($stage['time'] ?? ''),
                'inject' => (string) ($stage['inject'] ?? ''),
                'options' => $options,
            ];
        }

        return [
            'demo_id' => (string) $scenario['scenario_id'],
            'version' => $this->scenario->version(),
            'sha256' => $this->scenario->sha256(),
            'scenario_name' => (string) ($scenario['scenario_name'] ?? ''),
            'difficulty' => (string) ($scenario['difficulty'] ?? 'leadership'),
            'scored' => false,
            'persisted' => false,
            'stages' => $stages,
            'disclaimer' => (string) ($scenario['disclaimer'] ?? ''),
            'note' => 'This challenge is not scored and nothing is stored. It shows how a choice '
                . 'maps to rules and dimension movement, without any total.',
            'governance' => [
                'source_kind' => 'SIMULATION_ASSUMPTION',
                'counsel_reviewed' => false,
                'psychometrically_calibrated' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(string $stageId, string $choiceId): array
    {
        $this->requireAvailable();
        $scenario = $this->scenario->all();

        $options = null;

        foreach ((array) $scenario['stages'] as $stage) {
            if ((string) $stage['id'] === $stageId) {
                $options = (array) $stage['options'];
                break;
            }
        }

        if ($options === null) {
            throw DomainError::invalid("Unknown demo stage '{$stageId}'");
        }

        $chosen = null;

        foreach ($options as $option) {
            if ((string) $option['id'] === $choiceId) {
                $chosen = $option;
                break;
            }
        }

        if ($chosen === null) {
            throw DomainError::invalid("Unknown demo option '{$choiceId}'");
        }

        $mechanism = [];
        $byDimension = [];

        foreach ((array) $chosen['rules'] as $ruleId) {
            if (!$this->ruleset->has((string) $ruleId)) {
                throw DomainError::internal(
                    "Demo option references rule {$ruleId}, absent from ruleset {$this->rulesetVersion}"
                );
            }

            $rule = $this->ruleset->get((string) $ruleId);
            $mechanism[] = [
                'rule' => $rule->id,
                'title' => $rule->title,
                'deltas' => $rule->deltas,
                'source_kind' => $rule->sourceKind,
            ];

            foreach ($rule->deltas as $dimension => $value) {
                $byDimension[(string) $dimension] = ($byDimension[(string) $dimension] ?? 0)
                    + (int) $value;
            }
        }

        ksort($byDimension);

        return [
            'demo_id' => (string) $scenario['scenario_id'],
            'stage_id' => $stageId,
            'choice_id' => $choiceId,
            'choice_label' => (string) $chosen['label'],
            'mechanism' => $mechanism,
            'dimension_effect' => $byDimension,
            'scored' => false,
            'note' => 'This is the movement this one choice applies, not a running score. No total is '
                . 'calculated, so no total can be shown. Guards, clamping and risk appetite are not '
                . 'applied here; a scored run applies all three.',
            'governance' => [
                'source_kind' => 'SIMULATION_ASSUMPTION',
                'counsel_reviewed' => false,
                'psychometrically_calibrated' => false,
            ],
        ];
    }

    private function requireAvailable(): void
    {
        if (!$this->scenario->available()) {
            throw DomainError::conflict('The demonstration scenario is not installed on this deployment');
        }
    }
}
