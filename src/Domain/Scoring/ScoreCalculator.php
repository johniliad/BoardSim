<?php

declare(strict_types=1);

namespace BoardSim\Domain\Scoring;

use InvalidArgumentException;
use RuntimeException;

/**
 * Exact, repeatable scoring arithmetic (02_SCORING_MODEL.md, engine/RULE_SCHEMA.md).
 *
 * Computation order is normative:
 *   1. start every dimension at the configured baseline;
 *   2. for stages S1, S2, S3 then BOARD, add each rule's integer deltas in
 *      ascending rule-id order, so the trace never depends on authored order;
 *   3. add the risk-appetite modifier once per firing, to RJ only;
 *   4. clamp each dimension exactly once, after all rules;
 *   5. numerator = sum(weight * dimension); score = floor((numerator + 50) / 100).
 *
 * There is no floating point anywhere and no randomness, so identical inputs
 * always produce identical output.
 */
final class ScoreCalculator
{
    public const STAGE_ORDER = ['S1' => 1, 'S2' => 2, 'S3' => 3, 'BOARD' => 4];
    public const ROUNDING = 'half_up_positive_integer';

    /**
     * @param array<string, int> $weights         dimension code => weight percent
     * @param array<string, array<string, int>> $appetiteDeltas appetite => ruleId => RJ delta
     * @param list<array{min: int, label: string}> $ladder
     */
    public function __construct(
        private readonly array $weights,
        private readonly int $start,
        private readonly int $lower,
        private readonly int $upper,
        private readonly array $appetiteDeltas,
        private readonly array $ladder,
        private readonly string $rounding = self::ROUNDING,
    ) {
        if ($rounding !== self::ROUNDING) {
            throw new InvalidArgumentException("Unsupported rounding mode '{$rounding}'");
        }

        if ($lower > $upper || $start < $lower || $start > $upper) {
            throw new InvalidArgumentException('Inconsistent score bounds');
        }

        foreach (Dimension::codes() as $code) {
            if (!is_int($weights[$code] ?? null)) {
                throw new InvalidArgumentException("Missing or non-integer weight for {$code}");
            }
        }

        if (array_sum($weights) !== 100) {
            throw new InvalidArgumentException('Weights must sum to exactly 100');
        }
    }

    /**
     * @param list<array{stage: string, rule: Rule}> $firings
     *
     * @return array<string, mixed>
     */
    public function calculate(array $firings, string $appetite): array
    {
        if (!array_key_exists($appetite, $this->appetiteDeltas)) {
            throw new InvalidArgumentException("Unknown risk appetite '{$appetite}'");
        }

        $ordered = $this->orderFirings($firings);

        $dimensions = [];
        foreach (Dimension::codes() as $code) {
            $dimensions[$code] = $this->start;
        }

        $trace = [];

        foreach ($ordered as $firing) {
            $rule = $firing['rule'];
            $stage = $firing['stage'];

            $appetiteDelta = (int) ($this->appetiteDeltas[$appetite][$rule->id] ?? 0);
            $deltas = [];

            foreach (Dimension::codes() as $code) {
                $delta = $rule->deltaFor($code);
                $dimensions[$code] += $delta;
                // Log every addition, including zero, so the trace is complete.
                $deltas[$code] = $delta;
            }

            if ($appetiteDelta !== 0) {
                $dimensions['RJ'] += $appetiteDelta;
            }

            $trace[] = [
                'stage' => $stage,
                'rule' => $rule->id,
                'title' => $rule->title,
                'deltas' => $deltas,
                'risk_appetite_delta' => $appetiteDelta,
                'pre_clamp' => $dimensions,
                'evidence_refs' => $rule->evidenceRefs,
                'source_kind' => $rule->sourceKind,
            ];
        }

        $result = $this->finalise($dimensions);
        $result['basis'] = 'rule_trace';
        $result['appetite'] = $appetite;
        $result['trace'] = $trace;
        $result['firings'] = count($ordered);

        return $result;
    }

    /**
     * Score an already-assembled dimension vector. Used for the published golden
     * example and for verifying the formula independently of any rule trace.
     *
     * @param array<string, int> $dimensions
     *
     * @return array<string, mixed>
     */
    public function scoreFromDimensions(array $dimensions): array
    {
        foreach (Dimension::codes() as $code) {
            if (!is_int($dimensions[$code] ?? null)) {
                throw new InvalidArgumentException("Missing or non-integer dimension '{$code}'");
            }
        }

        $result = $this->finalise($dimensions);
        $result['basis'] = 'dimension_vector';
        $result['trace'] = [];

        return $result;
    }

    /**
     * @param array<string, int> $dimensions
     *
     * @return array<string, mixed>
     */
    private function finalise(array $dimensions): array
    {
        $clamped = [];

        foreach (Dimension::codes() as $code) {
            $value = $dimensions[$code];

            // Clamp exactly once, after every rule has been applied.
            $clamped[$code] = max($this->lower, min($this->upper, $value));
        }

        $contributions = [];
        $numerator = 0;

        foreach ($this->weights as $code => $weight) {
            $contribution = $weight * $clamped[$code];
            $contributions[$code] = $contribution;
            $numerator += $contribution;
        }

        $score = intdiv($numerator + 50, 100);

        return [
            'dimensions' => $clamped,
            'pre_clamp' => $dimensions,
            'weights' => $this->weights,
            'contributions' => $contributions,
            'numerator' => $numerator,
            'rounding' => 'floor((numerator + 50) / 100)',
            'score' => $score,
            'label' => $this->labelFor($score),
        ];
    }

    private function labelFor(int $score): string
    {
        foreach ($this->ladder as $band) {
            if ($score >= $band['min']) {
                return $band['label'];
            }
        }

        return 'unbanded';
    }

    /**
     * Deterministic ordering: stage first, then rule id ascending.
     *
     * @param list<array{stage: string, rule: Rule}> $firings
     *
     * @return list<array{stage: string, rule: Rule}>
     */
    private function orderFirings(array $firings): array
    {
        $seen = [];
        $keyed = [];

        foreach ($firings as $firing) {
            $stage = $firing['stage'];
            $rule = $firing['rule'];

            if (!isset(self::STAGE_ORDER[$stage])) {
                throw new InvalidArgumentException("Unknown stage '{$stage}' in firing set");
            }

            $slot = $stage . '|' . $rule->id;

            if (isset($seen[$slot])) {
                throw new RuntimeException(
                    "DuplicateFiring: {$rule->id} fired more than once in stage {$stage}"
                );
            }

            $seen[$slot] = true;
            // Numeric stage rank, so that a string sort yields S1, S2, S3, BOARD
            // (a literal stage name would sort BOARD ahead of S1).
            $keyed[sprintf('%d|%s', self::STAGE_ORDER[$stage], $rule->id)] = $firing;
        }

        ksort($keyed, SORT_STRING);

        return array_values($keyed);
    }
}
