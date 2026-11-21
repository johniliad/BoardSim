<?php

declare(strict_types=1);

namespace BoardSim\Content;

use BoardSim\Domain\Scoring\Dimension;
use BoardSim\Domain\Scoring\Rule;
use BoardSim\Domain\Scoring\Ruleset;
use BoardSim\Domain\Scoring\ScoreCalculator;
use RuntimeException;

/**
 * Loads and validates the normative scoring policy:
 *   engine/CONFIG.md                  arithmetic, weights, appetite modifiers
 *   requirements/03_DECISION_RULES.md the 47 rule deltas
 *   content/overlays/board_checklist.yaml  the board-pack rubric
 *
 * Everything is validated here so that the calculator can assume well-formed
 * integer input, and so that a policy error is a build failure rather than a
 * quiet mis-score.
 */
final class ScoringPolicyLoader
{
    private const APPETITES = ['conservative', 'balanced', 'aggressive'];

    /**
     * 02_SCORING_MODEL.md: appetite modifies RJ only for these four rules.
     */
    private const APPETITE_RULES = ['DR-002', 'DR-004', 'DR-007', 'DR-010'];

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @param string|null $version null loads the latest published policy, while
     *                             '1.0.0' loads the pinned baseline without the
     *                             calibration overlay, so archived runs stay
     *                             reproducible after a rebalance.
     *
     * @return array<string, mixed>
     */
    public function load(?string $version = null): array
    {
        $config = $this->yamlBlock($this->projectRoot . '/engine/CONFIG.md', 'engine/CONFIG.md');
        $rulesDocument = $this->yamlBlock(
            $this->projectRoot . '/requirements/03_DECISION_RULES.md',
            'requirements/03_DECISION_RULES.md'
        );

        $this->validateConfig($config);

        $activeCalibration = $this->loadCalibration();
        $pinnedVersion = (string) $config['ruleset_version'];
        $calibration = $activeCalibration;

        if ($version !== null
            && $version !== $pinnedVersion
            && ($activeCalibration === null || $version !== $activeCalibration['ruleset_version'])
        ) {
            $calibration = $this->loadCalibration($version);

            if ($calibration === null) {
                $available = array_filter([
                    $pinnedVersion,
                    $activeCalibration['ruleset_version'] ?? null,
                ]);
                throw new RuntimeException(
                    "UnknownRulesetVersion: '{$version}'; available: " . implode(', ', $available)
                );
            }
        }

        if ($calibration === null) {
            if ($version !== null && $version !== $pinnedVersion) {
                throw new RuntimeException("UnknownRulesetVersion: '{$version}'");
            }
        } elseif ($version === null || $version === $calibration['ruleset_version']) {
            [$config, $rulesDocument] = $this->applyCalibration($config, $rulesDocument, $calibration);
        } elseif ($version !== $pinnedVersion) {
            throw new RuntimeException(
                "UnknownRulesetVersion: '{$version}'; available: {$pinnedVersion}, {$calibration['ruleset_version']}"
            );
        }

        $ruleset = $this->buildRuleset($config, $rulesDocument);
        $board = $this->loadBoardChecklist();

        $calculator = new ScoreCalculator(
            weights: $config['weights_percent'],
            start: (int) $config['score_start_each_dimension'],
            lower: (int) $config['score_lower_bound'],
            upper: (int) $config['score_upper_bound'],
            appetiteDeltas: $config['risk_appetite_delta'],
            ladder: $config['score_ladder'],
            rounding: (string) $config['rounding'],
        );

        $policyPayload = [
            'ruleset_version' => (string) $config['ruleset_version'],
            'config' => $config,
            'rules' => array_map(
                static fn (Rule $rule): array => [
                    'id' => $rule->id,
                    'title' => $rule->title,
                    'deltas' => $rule->deltas,
                    'rationale' => $rule->rationale,
                    'evidence_refs' => $rule->evidenceRefs,
                    'source_kind' => $rule->sourceKind,
                ],
                array_values($ruleset->all())
            ),
            'board_checklist' => $board['fields'],
            'governance' => $board['provenance'],
        ];

        return [
            'config' => $config,
            'ruleset' => $ruleset,
            'calculator' => $calculator,
            'board_fields' => $board['fields'],
            'board_provenance' => $board['provenance'],
            'weights' => $config['weights_percent'],
            'appetite' => $config['risk_appetite_delta'],
            'ladder' => $config['score_ladder'],
            'personas' => $config['board_persona_display_only'],
            'ruleset_version' => (string) $config['ruleset_version'],
            'calibrated' => $calibration !== null && $version !== $pinnedVersion,
            'policy_sha256' => CanonicalJson::sha256($policyPayload),
        ];
    }

    /**
     * The optional calibration overlay. Its presence switches the default policy
     * from the pinned version to the calibrated one; requesting the pinned version
     * explicitly always bypasses it.
     *
     * @return array<string, mixed>|null
     */
    private function loadCalibration(?string $version = null): ?array
    {
        $path = $version === null
            ? $this->projectRoot . '/content/overlays/scoring_calibration.yaml'
            : $this->projectRoot . '/content/overlays/archive/scoring_calibration-' . $version . '.yaml';

        if (!is_file($path)) {
            return null;
        }

        $doc = Yaml::parse((string) file_get_contents($path));

        $version = (string) ($doc['ruleset_version'] ?? '');
        $supersedes = (string) ($doc['supersedes'] ?? '');

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException('scoring_calibration.yaml needs a semver ruleset_version');
        }

        if (preg_match('/^\d+\.\d+\.\d+$/', $supersedes) !== 1) {
            throw new RuntimeException('scoring_calibration.yaml needs a semver supersedes');
        }

        $provenance = $doc['provenance'] ?? null;

        if (!is_array($provenance)
            || !is_bool($provenance['counsel_reviewed'] ?? null)
            || !is_bool($provenance['psychometrically_calibrated'] ?? null)
            || (string) ($provenance['source_kind'] ?? '') !== 'SIMULATION_ASSUMPTION'
        ) {
            throw new RuntimeException('scoring_calibration.yaml must declare SIMULATION_ASSUMPTION provenance');
        }

        $overrides = $doc['delta_overrides'] ?? [];

        if (!is_array($overrides)) {
            throw new RuntimeException('delta_overrides must be a mapping');
        }

        $appetite = $doc['risk_appetite_delta'] ?? null;

        if ($appetite !== null && !is_array($appetite)) {
            throw new RuntimeException('risk_appetite_delta override must be a mapping');
        }

        $ladder = $doc['score_ladder'] ?? null;

        if ($ladder !== null && (!is_array($ladder) || $ladder === [])) {
            throw new RuntimeException('score_ladder override must be a non-empty list');
        }

        return [
            'ruleset_version' => $version,
            'supersedes' => $supersedes,
            'delta_overrides' => $overrides,
            'risk_appetite_delta' => $appetite,
            'score_ladder' => $ladder,
            'provenance' => $provenance,
            'calibration_note' => (string) ($doc['calibration_note'] ?? ''),
        ];
    }

    /**
     * Apply the calibration overlay to the pinned policy.
     *
     * Each override must restate the pinned delta it replaces, so that if the
     * pinned rule catalogue ever changes the overlay fails closed instead of
     * silently overriding a different value.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $rulesDocument
     * @param array<string, mixed> $calibration
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function applyCalibration(array $config, array $rulesDocument, array $calibration): array
    {
        if ($calibration['supersedes'] !== (string) $config['ruleset_version']) {
            throw new RuntimeException(
                "Calibration supersedes '{$calibration['supersedes']}' but the pinned ruleset is "
                . "'{$config['ruleset_version']}'"
            );
        }

        $overrides = $calibration['delta_overrides'];
        $applied = [];

        foreach ($rulesDocument['rules'] as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = (string) ($entry['id'] ?? '');

            if (!isset($overrides[$id])) {
                continue;
            }

            $override = $overrides[$id];

            if (!is_array($override) || !is_array($override['from'] ?? null) || !is_array($override['to'] ?? null)) {
                throw new RuntimeException("Calibration override {$id} needs both from and to");
            }

            $pinned = $entry['deltas'] ?? [];
            $claimed = $override['from'];

            ksort($pinned);
            ksort($claimed);

            if ($pinned !== $claimed) {
                throw new RuntimeException(
                    "Calibration override {$id} claims a 'from' profile that does not match the pinned deltas; "
                    . 'refusing to override a value it has not verified'
                );
            }

            $to = $override['to'];

            if ($to === []) {
                throw new RuntimeException("Calibration override {$id} has an empty 'to' profile");
            }

            foreach ($to as $dimension => $delta) {
                if (!Dimension::isValid((string) $dimension)) {
                    throw new RuntimeException("Calibration override {$id} uses unknown dimension '{$dimension}'");
                }

                if (!is_int($delta)) {
                    throw new RuntimeException("Calibration override {$id} delta for {$dimension} is not an integer");
                }
            }

            $rulesDocument['rules'][$index]['deltas'] = $to;
            $applied[$id] = true;
        }

        $unknown = array_diff(array_keys($overrides), array_keys($applied));

        if ($unknown !== []) {
            throw new RuntimeException(
                'Calibration override targets unknown rules: ' . implode(',', $unknown)
            );
        }

        if ($calibration['risk_appetite_delta'] !== null) {
            $config['risk_appetite_delta'] = $calibration['risk_appetite_delta'];
        }

        if ($calibration['score_ladder'] !== null) {
            $config['score_ladder'] = $calibration['score_ladder'];
        }

        $config['ruleset_version'] = $calibration['ruleset_version'];

        // Re-validate, because the appetite override is authored input too.
        $this->validateConfig($config);

        return [$config, $rulesDocument];
    }

    /**
     * @return array<string, mixed>
     */
    private function yamlBlock(string $path, string $label): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Missing policy file {$label}");
        }

        $markdown = (string) file_get_contents($path);

        if (preg_match('/```yaml\n(.*?)\n```/s', $markdown, $match) !== 1) {
            throw new RuntimeException("No normative YAML block in {$label}");
        }

        return Yaml::parse($match[1]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateConfig(array $config): void
    {
        foreach ([
            'ruleset_version',
            'score_start_each_dimension',
            'score_lower_bound',
            'score_upper_bound',
            'rounding',
            'weights_percent',
            'risk_appetite_delta',
            'board_persona_display_only',
            'score_ladder',
        ] as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException("engine/CONFIG.md is missing '{$key}'");
            }
        }

        if ((string) $config['rounding'] !== ScoreCalculator::ROUNDING) {
            throw new RuntimeException(
                "Unsupported rounding '{$config['rounding']}'; only exact integer half-up is permitted"
            );
        }

        foreach (['score_start_each_dimension', 'score_lower_bound', 'score_upper_bound'] as $key) {
            if (!is_int($config[$key])) {
                throw new RuntimeException("engine/CONFIG.md '{$key}' must be an integer");
            }
        }

        $lower = $config['score_lower_bound'];
        $upper = $config['score_upper_bound'];
        $start = $config['score_start_each_dimension'];

        if ($lower > $upper || $start < $lower || $start > $upper) {
            throw new RuntimeException('Inconsistent score bounds in engine/CONFIG.md');
        }

        $weights = $config['weights_percent'];
        if (!is_array($weights)) {
            throw new RuntimeException('weights_percent must be a mapping');
        }

        $expected = Dimension::codes();
        sort($expected);

        $actual = array_keys($weights);
        sort($actual);

        if ($actual !== $expected) {
            throw new RuntimeException(
                'weights_percent must cover exactly the ten canonical dimensions; found: ' . implode(',', $actual)
            );
        }

        foreach ($weights as $code => $weight) {
            if (!is_int($weight) || $weight < 0) {
                throw new RuntimeException("Weight for {$code} must be a non-negative integer");
            }
        }

        if (array_sum($weights) !== 100) {
            throw new RuntimeException('Weights must sum to exactly 100, found ' . array_sum($weights));
        }

        $appetite = $config['risk_appetite_delta'];
        if (!is_array($appetite)) {
            throw new RuntimeException('risk_appetite_delta must be a mapping');
        }

        foreach (self::APPETITES as $name) {
            if (!array_key_exists($name, $appetite) || !is_array($appetite[$name])) {
                throw new RuntimeException("Risk appetite '{$name}' is missing");
            }

            foreach ($appetite[$name] as $ruleId => $delta) {
                if (!in_array((string) $ruleId, self::APPETITE_RULES, true)) {
                    throw new RuntimeException(
                        "Risk appetite may only modify DR-002, DR-004, DR-007 and DR-010; found '{$ruleId}'"
                    );
                }

                if (!is_int($delta)) {
                    throw new RuntimeException("Appetite modifier for {$ruleId} must be an integer");
                }
            }
        }

        $ladder = $config['score_ladder'];
        if (!is_array($ladder) || $ladder === []) {
            throw new RuntimeException('score_ladder must be a non-empty list');
        }

        foreach ($ladder as $band) {
            if (!is_array($band) || !is_int($band['min'] ?? null) || !is_string($band['label'] ?? null)) {
                throw new RuntimeException('Each score_ladder band needs an integer min and a string label');
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $document
     */
    private function buildRuleset(array $config, array $document): Ruleset
    {
        $entries = $document['rules'] ?? null;

        if (!is_array($entries) || $entries === []) {
            throw new RuntimeException('03_DECISION_RULES.md declares no rules');
        }

        $rules = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Malformed rule entry in 03_DECISION_RULES.md');
            }

            $id = (string) ($entry['id'] ?? '');

            if (preg_match('/^DR-\d{3}$/', $id) !== 1) {
                throw new RuntimeException("Rule id '{$id}' does not match DR-nnn");
            }

            if (isset($rules[$id])) {
                throw new RuntimeException("DuplicateRuleId: {$id}");
            }

            $deltas = $entry['deltas'] ?? null;

            if (!is_array($deltas) || $deltas === []) {
                throw new RuntimeException("Rule {$id} declares no deltas");
            }

            foreach ($deltas as $dimension => $delta) {
                if (!Dimension::isValid((string) $dimension)) {
                    throw new RuntimeException("Rule {$id} uses unknown dimension '{$dimension}'");
                }

                if (!is_int($delta)) {
                    throw new RuntimeException("Rule {$id} delta for {$dimension} is not an integer");
                }
            }

            $title = (string) ($entry['title'] ?? '');
            $refs = $entry['evidence_refs'] ?? [];
            $sourceKind = (string) ($entry['source_kind'] ?? '');

            if ($title === '') {
                throw new RuntimeException("Rule {$id} has no title");
            }

            if (!is_array($refs) || $refs === []) {
                throw new RuntimeException("Rule {$id} has no evidence_refs");
            }

            if ($sourceKind !== 'BOARDSIM_POLICY') {
                throw new RuntimeException(
                    "Rule {$id} must declare source_kind BOARDSIM_POLICY, found '{$sourceKind}'"
                );
            }

            $rules[$id] = new Rule(
                id: $id,
                title: $title,
                deltas: $deltas,
                rationale: (string) ($entry['rationale'] ?? ''),
                evidenceRefs: array_values(array_map('strval', $refs)),
                sourceKind: $sourceKind,
            );
        }

        foreach (self::APPETITE_RULES as $id) {
            if (!isset($rules[$id])) {
                throw new RuntimeException("Appetite modifier targets missing rule {$id}");
            }
        }

        foreach (Ruleset::BOARD_RULE_IDS as $id) {
            if (!isset($rules[$id])) {
                throw new RuntimeException("Board checklist rule {$id} is missing from the ruleset");
            }
        }

        return new Ruleset((string) $config['ruleset_version'], $rules);
    }

    /**
     * @return array{fields: array<string, array<string, mixed>>, provenance: array<string, mixed>}
     */
    private function loadBoardChecklist(): array
    {
        $path = $this->projectRoot . '/content/overlays/board_checklist.yaml';

        if (!is_file($path)) {
            throw new RuntimeException('Missing content/overlays/board_checklist.yaml');
        }

        $doc = Yaml::parse((string) file_get_contents($path));

        $provenance = $doc['provenance'] ?? null;

        if (!is_array($provenance)
            || !is_bool($provenance['counsel_reviewed'] ?? null)
            || !is_bool($provenance['psychometrically_calibrated'] ?? null)
            || (string) ($provenance['source_kind'] ?? '') !== 'SIMULATION_ASSUMPTION'
        ) {
            throw new RuntimeException('board_checklist.yaml must declare SIMULATION_ASSUMPTION provenance');
        }

        $fields = $doc['fields'] ?? null;

        if (!is_array($fields) || count($fields) !== count(Ruleset::BOARD_RULE_IDS)) {
            throw new RuntimeException('board_checklist.yaml must define exactly seven fields');
        }

        $seenRules = [];

        foreach ($fields as $name => $definition) {
            if (!is_array($definition)) {
                throw new RuntimeException("Board field '{$name}' is malformed");
            }

            $rule = (string) ($definition['rule'] ?? '');

            if (!in_array($rule, Ruleset::BOARD_RULE_IDS, true)) {
                throw new RuntimeException("Board field '{$name}' maps to '{$rule}', not DR-041..DR-047");
            }

            if (isset($seenRules[$rule])) {
                throw new RuntimeException("Board rule {$rule} is mapped by more than one field");
            }

            $seenRules[$rule] = true;

            $options = $definition['options'] ?? null;

            if (!is_array($options) || count($options) < 2) {
                throw new RuntimeException("Board field '{$name}' needs at least two options");
            }

            $ids = [];
            $substantiated = 0;

            foreach ($options as $option) {
                if (!is_array($option)) {
                    throw new RuntimeException("Board field '{$name}' has a malformed option");
                }

                $optionId = (string) ($option['id'] ?? '');

                if ($optionId === '' || isset($ids[$optionId])) {
                    throw new RuntimeException("Board field '{$name}' has a missing or duplicate option id");
                }

                $ids[$optionId] = true;

                if (!is_bool($option['substantiated'] ?? null)) {
                    throw new RuntimeException("Board option {$name}/{$optionId} needs a boolean substantiated");
                }

                if ((string) ($option['label'] ?? '') === '' || (string) ($option['reason'] ?? '') === '') {
                    throw new RuntimeException("Board option {$name}/{$optionId} needs a label and a reason");
                }

                if ($option['substantiated'] === true) {
                    $substantiated++;
                }
            }

            if ($substantiated === 0) {
                throw new RuntimeException("Board field '{$name}' has no substantiated option");
            }

            if ($substantiated === count($options)) {
                throw new RuntimeException("Board field '{$name}' has no unsubstantiated option, so it is free");
            }
        }

        if (count($seenRules) !== count(Ruleset::BOARD_RULE_IDS)) {
            throw new RuntimeException('Board checklist must map all seven DR-041..DR-047 rules exactly once');
        }

        $this->assertFieldsMatchPinnedChecklists(array_keys($fields));

        /** @var array<string, array<string, mixed>> $fields */
        return ['fields' => $fields, 'provenance' => $provenance];
    }

    /**
     * Every pinned scenario declares the same seven board checklists, and the
     * overlay must use those exact names.
     *
     * @param list<string> $fieldNames
     */
    private function assertFieldsMatchPinnedChecklists(array $fieldNames): void
    {
        $pinned = (new PinnedScenarios($this->projectRoot))->loadAll();

        $reference = null;

        foreach ($pinned as $scenarioId => $scenario) {
            $list = $scenario['board']['checklists'] ?? [];

            if (!is_array($list)) {
                throw new RuntimeException("Scenario {$scenarioId} has malformed board.checklists");
            }

            $list = array_values(array_map('strval', $list));
            sort($list);

            if ($reference === null) {
                $reference = $list;

                continue;
            }

            if ($list !== $reference) {
                throw new RuntimeException("Scenario {$scenarioId} declares different board checklists");
            }
        }

        $ours = $fieldNames;
        sort($ours);

        if ($reference === null || $ours !== $reference) {
            throw new RuntimeException(
                'board_checklist.yaml fields do not match the pinned board checklists: ' . implode(',', $ours)
            );
        }
    }
}
