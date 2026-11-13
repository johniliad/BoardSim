<?php

declare(strict_types=1);

namespace BoardSim\Domain\Profile;

use BoardSim\Domain\Run\DomainError;
use BoardSim\Domain\Run\RunState;
use BoardSim\Domain\Run\RunStore;
use BoardSim\Domain\Scoring\Dimension;

/**
 * Portfolio aggregation over completed runs.
 *
 * 02_SCORING_MODEL.md is precise about the arithmetic:
 *   - each reported dimension is the UNWEIGHTED arithmetic mean of the completed
 *     scenario dimension scores, rounded half-up;
 *   - the profile overall is round_half_up(mean of the scenario overall scores);
 *   - the window is 3–5 completed scenarios;
 *   - different ruleset versions must never be silently merged.
 *
 * The F-5 decision adds a second dimension of separation: executive and
 * leadership are never merged either, because executive collects four board
 * fields instead of seven and therefore has a lower ceiling.
 */
final class ProfileAggregator
{
    public const MIN_RUNS = 3;
    public const MAX_RUNS = 5;

    /**
     * @param array<string, array<string, mixed>> $content scenario catalogue, for track metadata
     */
    public function __construct(
        private readonly RunStore $store,
        private readonly array $content,
        private readonly int $retentionDays = 7,
    ) {
    }

    /**
     * @param list<string> $runIds
     *
     * @return array<string, mixed>
     */
    public function aggregate(array $runIds): array
    {
        $runIds = array_values(array_unique(array_map('strval', $runIds)));

        if ($runIds === []) {
            throw DomainError::invalid('run_ids must contain at least one completed run');
        }

        $buckets = [];
        $allRuns = [];

        foreach ($runIds as $runId) {
            $run = $this->store->find($runId);

            if ($run === null) {
                throw DomainError::notFound('Run', $runId);
            }

            if ((string) $run['state'] !== RunState::Finalized->value) {
                throw DomainError::conflict(
                    "Run {$runId} is {$run['state']}; only finalized runs can be aggregated"
                );
            }

            // Segments are keyed on everything that changes the meaning of a
            // score. Merging any two of these would be a silent comparability
            // claim that the policy does not support.
            $key = implode('|', [
                (string) $run['ruleset_version'],
                (string) $run['content_version'],
                (string) $run['difficulty'],
            ]);

            $buckets[$key][] = $run;
            $allRuns[] = $run;
        }

        $segments = [];

        foreach ($buckets as $key => $runs) {
            $segments[] = $this->summarise((string) $key, $runs);
        }

        usort($segments, static fn (array $a, array $b): int => strcmp((string) $a['segment'], (string) $b['segment']));

        return [
            'segments' => $segments,
            'segments_count' => count($segments),
            'merged_across_versions' => false,
            'retention' => $this->retentionWindow($allRuns),
            'note' => 'Segment keys are ruleset version, scenario content version and difficulty. '
                . 'Scores are never merged across any of them. Comparability between participants '
                . 'is unvalidated; this is a self-development simulation, not an assessment.',
            'governance' => [
                'source_kind' => 'SIMULATION_ASSUMPTION',
                'counsel_reviewed' => false,
                'psychometrically_calibrated' => false,
            ],
        ];
    }

    /**
     * How long the profile has left before its oldest run is purged.
     *
     * With a 7-day ceiling, "3 of 5 scenarios" is a deadline, not just a count: a
     * participant who spreads runs across three weeks loses the earliest ones
     * before the profile can be completed. Saying so here is the difference between
     * a retention policy and a retention surprise.
     *
     * @param list<array<string, mixed>> $runs
     *
     * @return array<string, mixed>
     */
    private function retentionWindow(array $runs): array
    {
        $oldest = null;

        foreach ($runs as $run) {
            $at = (string) ($run['finalized_at'] ?? $run['created_at'] ?? '');

            if ($at !== '' && ($oldest === null || $at < $oldest)) {
                $oldest = $at;
            }
        }

        $expiresIn = null;

        if ($oldest !== null) {
            $age = time() - (int) strtotime($oldest);
            $remaining = ($this->retentionDays * 86400) - $age;
            $expiresIn = (int) ceil(max(0, $remaining) / 86400);
        }

        return [
            'run_retention_days' => $this->retentionDays,
            'oldest_run_finalized_at' => $oldest,
            'oldest_expires_in_days' => $expiresIn,
            'note' => 'Every run in a profile is deleted ' . $this->retentionDays
                . ' days after it was finalised. Complete the 3 to 5 scenarios inside that window.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $runs
     *
     * @return array<string, mixed>
     */
    private function summarise(string $segment, array $runs): array
    {
        $count = count($runs);
        $first = $runs[0];

        $base = [
            'segment' => $segment,
            'ruleset_version' => (string) $first['ruleset_version'],
            'content_version' => (string) $first['content_version'],
            'difficulty' => (string) $first['difficulty'],
            'runs' => $count,
            'caveats' => [],
        ];

        $scenarioIds = [];
        $tracks = [];
        $versions = [];
        $appetites = [];

        foreach ($runs as $run) {
            $scenarioId = (string) $run['scenario_id'];
            $scenarioIds[$scenarioId] = ($scenarioIds[$scenarioId] ?? 0) + 1;
            $versions[(string) $run['scenario_version']] = true;
            $appetites[(string) $run['risk_appetite']] = true;

            $track = (string) ($this->content[$scenarioId]['track'] ?? 'unknown');
            $tracks[$track] = ($tracks[$track] ?? 0) + 1;
        }

        $base['coverage'] = [
            'distinct_scenarios' => count($scenarioIds),
            'scenario_ids' => array_keys($scenarioIds),
            'tracks' => $tracks,
            'scenario_versions' => array_keys($versions),
            'risk_appetites' => array_keys($appetites),
        ];

        // Comparability caveats belong to the run set, not to whether the set
        // happens to be inside the window, so they are recorded before any
        // early return.
        $repeated = array_filter($scenarioIds, static fn (int $times): bool => $times > 1);

        if ($repeated !== []) {
            $base['caveats'][] = sprintf(
                'Repeated scenario(s) counted more than once: %s. Comparability across repeats is unvalidated.',
                implode(', ', array_keys($repeated))
            );
        }

        // The 3–5 window is a spec requirement, not a preference. Reporting a
        // number outside it would present an unsupported aggregate.
        if ($count < self::MIN_RUNS) {
            $base['status'] = 'insufficient';
            $base['dimensions'] = null;
            $base['overall'] = null;
            $base['caveats'][] = sprintf(
                'Only %d completed scenario(s) in this segment; a profile needs %d to %d.',
                $count,
                self::MIN_RUNS,
                self::MAX_RUNS
            );

            return $base;
        }

        if ($count > self::MAX_RUNS) {
            $base['status'] = 'over_window';
            $base['dimensions'] = null;
            $base['overall'] = null;
            $base['caveats'][] = sprintf(
                '%d runs in this segment; the profile window is %d to %d. Select which runs to include.',
                $count,
                self::MIN_RUNS,
                self::MAX_RUNS
            );

            return $base;
        }

        $dimensionTotals = array_fill_keys(Dimension::codes(), 0);
        $scoreTotal = 0;

        foreach ($runs as $run) {
            $dimensions = (array) $run['dimensions'];

            foreach (Dimension::codes() as $code) {
                $dimensionTotals[$code] += (int) ($dimensions[$code] ?? 0);
            }

            $scoreTotal += (int) $run['score'];
        }

        $aggregated = [];

        foreach (Dimension::codes() as $code) {
            $aggregated[$code] = self::roundHalfUp($dimensionTotals[$code] / $count);
        }

        $base['status'] = 'eligible';
        $base['dimensions'] = $aggregated;
        $base['overall'] = self::roundHalfUp($scoreTotal / $count);
        $base['arithmetic'] = 'dimension = round_half_up(mean of run dimensions); '
            . 'overall = round_half_up(mean of run overall scores).';

        return $base;
    }

    /**
     * Round half up, on non-negative values only, matching the scoring model's
     * integer discipline. No floats survive into the result.
     */
    private static function roundHalfUp(float $value): int
    {
        return (int) floor($value + 0.5);
    }
}
