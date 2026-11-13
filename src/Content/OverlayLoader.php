<?php

declare(strict_types=1);

namespace BoardSim\Content;

use BoardSim\Domain\Regulation\Instrument;
use BoardSim\Domain\Regulation\PredicateExpression;
use RuntimeException;

/**
 * Loads the authored overlay layer and refuses to produce a bundle that violates
 * any invariant. Every check here exists because engine/RULE_SCHEMA.md and
 * 01_ARCHITECTURE.md require deterministic rejection rather than a silent default.
 */
final class OverlayLoader
{
    private const NATURES = ['LAW', 'NATIONAL_LAW', 'FRAMEWORK', 'GUIDANCE'];
    private const TIERS = ['VERIFIED_TOP_LEVEL_LAW', 'REQUIRES_COUNSEL_GATE', 'GUIDANCE'];
    private const GUARD_KINDS = ['ANY_REPORTING_INSTRUMENT_TRIGGERED'];
    private const GUARD_UNMET = ['SUPPRESS_AND_RECORD'];
    private const FACT_TYPES = ['tri_bool', 'timestamp_iso8601'];
    private const PROVENANCE_KINDS = ['AUTHORITATIVE_SOURCE', 'SIMULATION_ASSUMPTION'];
    private const STATUSES = [
        'NOT_APPLICABLE',
        'APPLICABLE_NOT_TRIGGERED',
        'TRIGGERED',
        'PENDING_CLASSIFICATION',
        'REQUIRES_HUMAN_REVIEW',
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $vocabulary = $this->loadVocabulary();
        $instruments = $this->loadInstruments($vocabulary);
        $scenarioFacts = $this->loadScenarioFacts($vocabulary);
        [$ruleDefinitions, $bindings] = $this->loadGuards($instruments, $scenarioFacts);

        $pinned = (new PinnedScenarios($this->projectRoot))->loadAll();
        $this->crossValidate($pinned, $instruments, $scenarioFacts, $ruleDefinitions, $bindings);

        $reportingIds = [];
        foreach ($instruments as $instrument) {
            if ($instrument->reportingDuty) {
                $reportingIds[] = $instrument->id;
            }
        }

        $bundle = $this->buildBundle($instruments, $scenarioFacts, $ruleDefinitions, $bindings, $pinned);

        return [
            'vocabulary' => $vocabulary,
            'instruments' => $instruments,
            'scenario_facts' => $scenarioFacts,
            'rule_definitions' => $ruleDefinitions,
            'guard_bindings' => $bindings,
            'reporting_instrument_ids' => $reportingIds,
            'pinned_scenarios' => $pinned,
            'bundle' => $bundle,
            'bundle_sha256' => CanonicalJson::sha256($bundle),
        ];
    }

    /**
     * @return array{predicates: array<string, array<string, mixed>>, timer_events: array<string, mixed>, fact_source_kinds: list<string>}
     */
    private function loadVocabulary(): array
    {
        $doc = $this->readYaml('predicate_vocabulary.yaml');

        $predicates = $doc['predicates'] ?? null;
        if (!is_array($predicates) || $predicates === []) {
            throw new RuntimeException('predicate_vocabulary.yaml declares no predicates');
        }

        foreach ($predicates as $name => $definition) {
            if (!is_array($definition)) {
                throw new RuntimeException("Predicate '{$name}' has no definition block");
            }

            $type = (string) ($definition['type'] ?? '');
            if (!in_array($type, self::FACT_TYPES, true)) {
                throw new RuntimeException("Predicate '{$name}' has unknown type '{$type}'");
            }
        }

        $timerEvents = $doc['timer_events'] ?? [];
        if (!is_array($timerEvents)) {
            throw new RuntimeException('timer_events must be a mapping');
        }

        $sourceKinds = $doc['fact_source_kinds'] ?? [];
        if (!is_array($sourceKinds) || $sourceKinds === []) {
            throw new RuntimeException('fact_source_kinds must be a non-empty list');
        }

        return [
            'predicates' => $predicates,
            'timer_events' => $timerEvents,
            'fact_source_kinds' => array_values(array_map('strval', $sourceKinds)),
        ];
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    private function provenanceDefaults(array $doc, string $context): array
    {
        $defaults = $doc['provenance'] ?? null;

        if (!is_array($defaults)) {
            throw new RuntimeException("{$context} must declare a provenance block");
        }

        $kind = (string) ($defaults['source_kind'] ?? '');
        if (!in_array($kind, self::PROVENANCE_KINDS, true)) {
            throw new RuntimeException("{$context} provenance.source_kind is invalid");
        }

        foreach (['counsel_reviewed', 'psychometrically_calibrated'] as $flag) {
            if (!is_bool($defaults[$flag] ?? null)) {
                throw new RuntimeException("{$context} provenance.{$flag} must be a boolean");
            }
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $defaults
     *
     * @return array{source_kind: string, counsel_reviewed: bool, psychometrically_calibrated: bool}
     */
    private function resolveProvenance(array $entry, string $context, array $defaults): array
    {
        $kind = (string) ($entry['source_kind'] ?? $defaults['source_kind']);

        if (!in_array($kind, self::PROVENANCE_KINDS, true)) {
            throw new RuntimeException("{$context} declares an invalid source_kind '{$kind}'");
        }

        $reviewed = $entry['counsel_reviewed'] ?? $defaults['counsel_reviewed'];
        $calibrated = $entry['psychometrically_calibrated'] ?? $defaults['psychometrically_calibrated'];

        if (!is_bool($reviewed) || !is_bool($calibrated)) {
            throw new RuntimeException("{$context} must declare boolean governance flags");
        }

        return [
            'source_kind' => $kind,
            'counsel_reviewed' => $reviewed,
            'psychometrically_calibrated' => $calibrated,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function requireField(array $entry, string $key, string $context): mixed
    {
        if (!array_key_exists($key, $entry) || $entry[$key] === null || $entry[$key] === '') {
            throw new RuntimeException("{$context} is missing required field '{$key}'");
        }

        return $entry[$key];
    }

    /**
     * @param array{predicates: array<string, array<string, mixed>>, timer_events: array<string, mixed>, fact_source_kinds: list<string>} $vocabulary
     *
     * @return array<string, Instrument>
     */
    private function loadInstruments(array $vocabulary): array
    {
        $doc = $this->readYaml('regulatory_instruments.yaml');
        $defaults = $this->provenanceDefaults($doc, 'regulatory_instruments.yaml');

        $entries = $doc['instruments'] ?? null;
        if (!is_array($entries) || $entries === []) {
            throw new RuntimeException('regulatory_instruments.yaml declares no instruments');
        }

        $instruments = [];

        foreach ($entries as $rawId => $entry) {
            $id = (string) $rawId;

            if (preg_match('/^REG-[A-Z0-9-]+$/', $id) !== 1) {
                throw new RuntimeException("Instrument id '{$id}' does not match REG-[A-Z0-9-]+");
            }

            if (!is_array($entry)) {
                throw new RuntimeException("Instrument {$id} is not a mapping");
            }

            $nature = (string) $this->requireField($entry, 'nature', $id);
            if (!in_array($nature, self::NATURES, true)) {
                throw new RuntimeException("Instrument {$id} has unknown nature '{$nature}'");
            }

            $tier = (string) $this->requireField($entry, 'verification_tier', $id);
            if (!in_array($tier, self::TIERS, true)) {
                throw new RuntimeException("Instrument {$id} has unknown verification_tier '{$tier}'");
            }

            $provenance = $this->resolveProvenance($entry, $id, $defaults);

            $signedOff = $entry['counsel_signed_off'] ?? null;
            if (!is_bool($signedOff)) {
                throw new RuntimeException("Instrument {$id} must declare counsel_signed_off as a boolean");
            }

            if ($signedOff && !$provenance['counsel_reviewed']) {
                throw new RuntimeException("Instrument {$id} claims counsel_signed_off but not counsel_reviewed");
            }

            $reportingDuty = $entry['reporting_duty'] ?? null;
            if (!is_bool($reportingDuty)) {
                throw new RuntimeException("Instrument {$id} must declare reporting_duty as a boolean");
            }

            $effectiveFrom = $entry['effective_from_date'] ?? null;
            if ($effectiveFrom !== null
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $effectiveFrom) !== 1
            ) {
                throw new RuntimeException("Instrument {$id} has a malformed effective_from_date");
            }

            $isGuidance = in_array($nature, ['FRAMEWORK', 'GUIDANCE'], true) || $tier === 'GUIDANCE';
            $scope = null;
            $trigger = null;

            if (!$isGuidance) {
                $scope = $this->buildExpression(
                    (string) $this->requireField($entry, 'scope_expr', $id),
                    $vocabulary,
                    "{$id}.scope_expr"
                );
                $trigger = $this->buildExpression(
                    (string) $this->requireField($entry, 'trigger_expr', $id),
                    $vocabulary,
                    "{$id}.trigger_expr"
                );
            }

            $timers = $entry['timers'] ?? [];
            if (!is_array($timers)) {
                throw new RuntimeException("Instrument {$id} timers must be a list");
            }

            $this->validateTimers($id, $timers, $vocabulary, $trigger !== null);

            $related = $entry['related_urls'] ?? [];
            if (!is_array($related)) {
                throw new RuntimeException("Instrument {$id} related_urls must be a list");
            }

            $instruments[$id] = new Instrument(
                id: $id,
                title: (string) $this->requireField($entry, 'title', $id),
                sourceUrl: (string) $this->requireField($entry, 'source_url', $id),
                nature: $nature,
                verificationTier: $tier,
                reportingDuty: $reportingDuty,
                counselSignedOff: $signedOff,
                scopeExpr: $scope,
                triggerExpr: $trigger,
                timers: $timers,
                effectiveFromDate: $effectiveFrom === null ? null : (string) $effectiveFrom,
                sourceKind: $provenance['source_kind'],
                counselReviewed: $provenance['counsel_reviewed'],
                psychometricallyCalibrated: $provenance['psychometrically_calibrated'],
                relatedUrls: array_values(array_map('strval', $related)),
            );
        }

        return $instruments;
    }

    /**
     * @param list<array<string, mixed>> $timers
     * @param array{predicates: array<string, array<string, mixed>>, timer_events: array<string, mixed>, fact_source_kinds: list<string>} $vocabulary
     */
    private function validateTimers(string $id, array $timers, array $vocabulary, bool $hasTrigger): void
    {
        if ($timers === []) {
            return;
        }

        if (!$hasTrigger) {
            throw new RuntimeException("Guidance instrument {$id} must not declare statutory timers");
        }

        $seen = [];

        foreach ($timers as $timer) {
            if (!is_array($timer)) {
                throw new RuntimeException("Instrument {$id} has a malformed timer");
            }

            $timerId = (string) $this->requireField($timer, 'id', "{$id}.timer");
            if (isset($seen[$timerId])) {
                throw new RuntimeException("Instrument {$id} declares duplicate timer {$timerId}");
            }
            $seen[$timerId] = true;

            $this->requireField($timer, 'label', "{$id}.{$timerId}");
            $windowType = (string) ($timer['window_type'] ?? 'FIXED_OFFSET');

            if ($windowType === 'WITHOUT_UNDUE_DELAY') {
                continue;
            }

            $anchorFact = $timer['anchor_fact'] ?? null;
            if (is_string($anchorFact) && $anchorFact !== '') {
                if (!isset($vocabulary['predicates'][$anchorFact])) {
                    throw new RuntimeException("Timer {$id}.{$timerId} anchors on undeclared fact '{$anchorFact}'");
                }
                continue;
            }

            $anchorEvent = $timer['anchor_event'] ?? null;
            if (is_string($anchorEvent) && $anchorEvent !== '') {
                if (!isset($vocabulary['timer_events'][$anchorEvent])) {
                    throw new RuntimeException("Timer {$id}.{$timerId} anchors on undeclared event '{$anchorEvent}'");
                }

                $projection = $timer['projection_from_timer'] ?? null;
                if (!is_string($projection) || !isset($this->timerIdsOf($timers)[$projection])) {
                    throw new RuntimeException(
                        "Timer {$id}.{$timerId} needs projection_from_timer naming a timer in the same instrument"
                    );
                }

                continue;
            }

            $clockRule = $timer['clock_rule'] ?? null;
            if ($clockRule !== 'DORA_ART5_INITIAL') {
                throw new RuntimeException("Timer {$id}.{$timerId} has no usable anchor");
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $timers
     *
     * @return array<string, true>
     */
    private function timerIdsOf(array $timers): array
    {
        $ids = [];

        foreach ($timers as $timer) {
            if (is_array($timer) && isset($timer['id'])) {
                $ids[(string) $timer['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array{predicates: array<string, array<string, mixed>>, timer_events: array<string, mixed>, fact_source_kinds: list<string>} $vocabulary
     */
    private function buildExpression(string $source, array $vocabulary, string $context): PredicateExpression
    {
        foreach (PredicateExpression::identifiers($source) as $identifier) {
            if (!isset($vocabulary['predicates'][$identifier])) {
                throw new RuntimeException(
                    "Undeclared predicate '{$identifier}' in {$context}; add it to predicate_vocabulary.yaml"
                );
            }
        }

        return new PredicateExpression($source);
    }

    /**
     * @param array{predicates: array<string, array<string, mixed>>, timer_events: array<string, mixed>, fact_source_kinds: list<string>} $vocabulary
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadScenarioFacts(array $vocabulary): array
    {
        $doc = $this->readYaml('scenario_regulatory_facts.yaml');
        $defaults = $this->provenanceDefaults($doc, 'scenario_regulatory_facts.yaml');

        $scenarios = $doc['scenarios'] ?? null;
        if (!is_array($scenarios) || $scenarios === []) {
            throw new RuntimeException('scenario_regulatory_facts.yaml declares no scenarios');
        }

        $out = [];

        foreach ($scenarios as $rawId => $block) {
            $scenarioId = (string) $rawId;
            if (!is_array($block)) {
                throw new RuntimeException("Scenario {$scenarioId} fact block is not a mapping");
            }

            $provenance = $this->resolveProvenance($block, $scenarioId, $defaults);

            $facts = $block['regulatory_facts'] ?? [];
            if (!is_array($facts)) {
                throw new RuntimeException("Scenario {$scenarioId} regulatory_facts must be a list");
            }

            $seenIds = [];
            $seenSlot = [];
            $normalized = [];

            foreach ($facts as $fact) {
                if (!is_array($fact)) {
                    throw new RuntimeException("Scenario {$scenarioId} has a malformed fact");
                }

                $factId = (string) $this->requireField($fact, 'id', $scenarioId);
                if (isset($seenIds[$factId])) {
                    throw new RuntimeException("Duplicate fact id {$factId}");
                }
                $seenIds[$factId] = true;

                $key = (string) $this->requireField($fact, 'key', $factId);
                if (!isset($vocabulary['predicates'][$key])) {
                    throw new RuntimeException(
                        "Fact {$factId} uses undeclared predicate key '{$key}'; typos must fail at load time"
                    );
                }

                $type = (string) $this->requireField($fact, 'type', $factId);
                if (!in_array($type, self::FACT_TYPES, true)) {
                    throw new RuntimeException("Fact {$factId} has unknown type '{$type}'");
                }

                $declaredType = (string) $vocabulary['predicates'][$key]['type'];
                if ($type !== $declaredType) {
                    throw new RuntimeException(
                        "Fact {$factId} declares type {$type} but '{$key}' is {$declaredType}"
                    );
                }

                $stage = (string) $this->requireField($fact, 'visible_from_stage', $factId);
                if (!in_array($stage, ['S1', 'S2', 'S3'], true)) {
                    throw new RuntimeException("Fact {$factId} has invalid stage '{$stage}'");
                }

                $sourceKind = (string) $this->requireField($fact, 'source_kind', $factId);
                if (!in_array($sourceKind, $vocabulary['fact_source_kinds'], true)) {
                    throw new RuntimeException("Fact {$factId} has invalid source_kind '{$sourceKind}'");
                }

                $slot = "{$stage}|{$key}";
                if (isset($seenSlot[$slot])) {
                    throw new RuntimeException("Two facts assert '{$key}' at stage {$stage} in {$scenarioId}");
                }
                $seenSlot[$slot] = true;

                $value = $fact['value'] ?? null;

                if ($type === 'tri_bool') {
                    \BoardSim\Domain\Regulation\TriBool::fromAuthored($value, $factId);
                } elseif ($value !== null
                    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $value) !== 1
                ) {
                    throw new RuntimeException("Fact {$factId} is not an ISO-8601 UTC timestamp");
                }

                $normalized[] = [
                    'id' => $factId,
                    'key' => $key,
                    'type' => $type,
                    'value' => $value,
                    'visible_from_stage' => $stage,
                    'source_kind' => $sourceKind,
                    'rationale' => (string) ($fact['rationale'] ?? ''),
                ];
            }

            $out[$scenarioId] = ['facts' => $normalized, 'provenance' => $provenance];
        }

        return $out;
    }

    /**
     * @param array<string, Instrument> $instruments
     * @param array<string, array<string, mixed>> $scenarioFacts
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, array<string, list<array<string, mixed>>>>>}
     */
    private function loadGuards(array $instruments, array $scenarioFacts): array
    {
        $doc = $this->readYaml('rule_guards.yaml');
        $defaults = $this->provenanceDefaults($doc, 'rule_guards.yaml');

        $rawDefinitions = $doc['rule_definitions'] ?? null;
        if (!is_array($rawDefinitions) || $rawDefinitions === []) {
            throw new RuntimeException('rule_guards.yaml declares no rule_definitions');
        }

        $definitions = [];

        foreach ($rawDefinitions as $rawRuleId => $definition) {
            $ruleId = (string) $rawRuleId;
            if (!is_array($definition)) {
                throw new RuntimeException("Rule definition {$ruleId} is not a mapping");
            }

            $kind = (string) $this->requireField($definition, 'required_guard', $ruleId);
            if (!in_array($kind, self::GUARD_KINDS, true)) {
                throw new RuntimeException("Rule {$ruleId} has unknown required_guard '{$kind}'");
            }

            $onUnmet = (string) ($definition['on_guard_unmet'] ?? 'SUPPRESS_AND_RECORD');
            if (!in_array($onUnmet, self::GUARD_UNMET, true)) {
                throw new RuntimeException("Rule {$ruleId} has unsupported on_guard_unmet '{$onUnmet}'");
            }

            if (($definition['fail_closed_on_missing_guard'] ?? null) !== true) {
                throw new RuntimeException("Rule {$ruleId} must set fail_closed_on_missing_guard: true");
            }

            if (($definition['substitution_permitted'] ?? null) !== false) {
                throw new RuntimeException(
                    "Rule {$ruleId} must set substitution_permitted: false; "
                    . 'engine/RULE_SCHEMA.md forbids hidden fallback rules'
                );
            }

            $this->resolveProvenance($definition, $ruleId, $defaults);

            $definitions[$ruleId] = [
                'rule' => $ruleId,
                'required_guard' => $kind,
                'on_guard_unmet' => $onUnmet,
                'fail_closed_on_missing_guard' => true,
                'substitution_permitted' => false,
                'rationale' => (string) ($definition['rationale'] ?? ''),
            ];
        }

        $bindings = $this->loadBindings(
            $doc['guard_bindings'] ?? [],
            $definitions,
            $instruments,
            $scenarioFacts,
            $defaults
        );

        return [$definitions, $bindings];
    }

    /**
     * @param mixed $rawBindings
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, Instrument> $instruments
     * @param array<string, array<string, mixed>> $scenarioFacts
     * @param array<string, mixed> $defaults
     *
     * @return array<string, array<string, array<string, list<array<string, mixed>>>>>
     */
    private function loadBindings(
        mixed $rawBindings,
        array $definitions,
        array $instruments,
        array $scenarioFacts,
        array $defaults
    ): array {
        if (!is_array($rawBindings)) {
            throw new RuntimeException('guard_bindings must be a mapping');
        }

        $bindings = [];

        foreach ($rawBindings as $rawScenario => $stages) {
            $scenarioId = (string) $rawScenario;

            if (!isset($scenarioFacts[$scenarioId])) {
                throw new RuntimeException("Guard binding for {$scenarioId} has no authored fact set");
            }

            if (!is_array($stages)) {
                throw new RuntimeException("Guard bindings for {$scenarioId} must be a mapping");
            }

            foreach ($stages as $stage => $rules) {
                $stage = (string) $stage;

                if (!in_array($stage, ['S1', 'S2', 'S3'], true)) {
                    throw new RuntimeException("Guard binding for {$scenarioId} has invalid stage '{$stage}'");
                }

                if (!is_array($rules)) {
                    throw new RuntimeException("Guard bindings for {$scenarioId}/{$stage} must be a mapping");
                }

                foreach ($rules as $rawRuleId => $list) {
                    $ruleId = (string) $rawRuleId;

                    if (!isset($definitions[$ruleId])) {
                        throw new RuntimeException(
                            "Guard binding {$scenarioId}/{$stage}/{$ruleId} refers to an undefined rule"
                        );
                    }

                    if (!is_array($list)) {
                        throw new RuntimeException("Guard binding {$scenarioId}/{$stage}/{$ruleId} must be a list");
                    }

                    foreach ($list as $binding) {
                        if (!is_array($binding)) {
                            throw new RuntimeException("Malformed guard binding {$scenarioId}/{$stage}/{$ruleId}");
                        }

                        $context = "{$scenarioId}/{$stage}/{$ruleId}";
                        $instrumentId = (string) $this->requireField($binding, 'instrument', $context);

                        if (!isset($instruments[$instrumentId])) {
                            throw new RuntimeException("Guard binding references unknown instrument '{$instrumentId}'");
                        }

                        $expected = (string) $this->requireField($binding, 'expected_status', $context);
                        if (!in_array($expected, self::STATUSES, true)) {
                            throw new RuntimeException("Guard binding has invalid expected_status '{$expected}'");
                        }

                        $guardRef = (string) $this->requireField($binding, 'guard_ref', $context);
                        $signedOff = $binding['counsel_signed_off'] ?? false;
                        if (!is_bool($signedOff)) {
                            throw new RuntimeException('Guard binding counsel_signed_off must be a boolean');
                        }

                        $provenance = $this->resolveProvenance($binding, $context, $defaults);

                        $bindings[$scenarioId][$stage][$ruleId][] = [
                            'guard_ref' => $guardRef,
                            'instrument' => $instrumentId,
                            'expected_status' => $expected,
                            'counsel_signed_off' => $signedOff,
                            'source_kind' => $provenance['source_kind'],
                            'note' => (string) ($binding['note'] ?? ''),
                        ];
                    }
                }
            }
        }

        return $bindings;
    }

    /**
     * @param array<string, array<string, mixed>> $pinned
     * @param array<string, Instrument> $instruments
     * @param array<string, array<string, mixed>> $scenarioFacts
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<string, array<string, list<array<string, mixed>>>>> $bindings
     */
    private function crossValidate(
        array $pinned,
        array $instruments,
        array $scenarioFacts,
        array $definitions,
        array $bindings
    ): void {
        foreach (array_keys($scenarioFacts) as $scenarioId) {
            if (!isset($pinned[$scenarioId])) {
                throw new RuntimeException("Fact overlay targets unknown scenario '{$scenarioId}'");
            }
        }

        foreach ($pinned as $scenarioId => $scenario) {
            foreach ($scenario['regulatory_refs'] as $ref) {
                if (!isset($instruments[$ref])) {
                    throw new RuntimeException(
                        "UnknownLegalSource: {$scenarioId} references '{$ref}', which is not in the registry"
                    );
                }
            }

            foreach ($scenario['rule_guards'] as $guard) {
                if (!is_array($guard)) {
                    throw new RuntimeException("Malformed rule_guards entry in {$scenarioId}");
                }

                $stage = (string) ($guard['stage'] ?? '');
                $rule = (string) ($guard['rule'] ?? '');
                $name = (string) ($guard['guard'] ?? '');

                if ($stage === '' || $rule === '' || $name === '') {
                    throw new RuntimeException("Incomplete rule_guards entry in {$scenarioId}");
                }

                if (!isset($definitions[$rule])) {
                    throw new RuntimeException(
                        "Guarded rule {$rule} used by {$scenarioId} has no rule definition"
                    );
                }

                $matched = false;
                foreach ($bindings[$scenarioId][$stage][$rule] ?? [] as $binding) {
                    if ($binding['guard_ref'] === $name) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    throw new RuntimeException(
                        "GuardMissing: {$scenarioId}/{$stage}/{$rule} names guard '{$name}' "
                        . 'but no binding declares it'
                    );
                }
            }
        }
    }

    /**
     * @param array<string, Instrument> $instruments
     * @param array<string, array<string, mixed>> $scenarioFacts
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, array<string, array<string, list<array<string, mixed>>>>> $bindings
     * @param array<string, array<string, mixed>> $pinned
     *
     * @return array<string, mixed>
     */
    private function buildBundle(
        array $instruments,
        array $scenarioFacts,
        array $definitions,
        array $bindings,
        array $pinned
    ): array {
        $instrumentPayload = [];

        foreach ($instruments as $id => $instrument) {
            $instrumentPayload[$id] = [
                'title' => $instrument->title,
                'source_url' => $instrument->sourceUrl,
                'nature' => $instrument->nature,
                'verification_tier' => $instrument->verificationTier,
                'reporting_duty' => $instrument->reportingDuty,
                'counsel_signed_off' => $instrument->counselSignedOff,
                'scope_expr' => $instrument->scopeExpr?->source(),
                'trigger_expr' => $instrument->triggerExpr?->source(),
                'effective_from_date' => $instrument->effectiveFromDate,
                'timers' => $instrument->timers,
                'related_urls' => $instrument->relatedUrls,
                'provenance' => [
                    'source_kind' => $instrument->sourceKind,
                    'counsel_reviewed' => $instrument->counselReviewed,
                    'psychometrically_calibrated' => $instrument->psychometricallyCalibrated,
                ],
            ];
        }

        $scenarioPayload = [];

        foreach ($pinned as $scenarioId => $scenario) {
            $scenarioPayload[$scenarioId] = [
                'version' => $scenario['version'],
                'scenario_name' => $scenario['scenario_name'],
                'industry' => $scenario['industry'],
                'track' => $scenario['track'],
                'difficulty' => $scenario['difficulty'],
                'regulatory_refs' => $scenario['regulatory_refs'],
                'rule_guards' => $scenario['rule_guards'],
                'source_file' => $scenario['source_file'],
            ];
        }

        return [
            'schema_version' => '1.0.0',
            'regulatory_overlay_version' => '1.1.0',
            'ruleset_version' => '1.0.0',
            'scenario_versions' => $scenarioPayload,
            'instruments' => $instrumentPayload,
            'scenario_facts' => $scenarioFacts,
            'rule_definitions' => $definitions,
            'guard_bindings' => $bindings,
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
    private function readYaml(string $file): array
    {
        $path = $this->projectRoot . '/content/overlays/' . $file;

        if (!is_file($path)) {
            throw new RuntimeException("Missing overlay file {$file}");
        }

        return Yaml::parse((string) file_get_contents($path));
    }
}
