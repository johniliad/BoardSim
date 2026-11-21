<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

use BoardSim\Content\BundleSeeder;
use BoardSim\Content\CanonicalJson;
use BoardSim\Content\EvidenceJson;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;
use BoardSim\Content\Glossary;
use BoardSim\Domain\Regulation\FactSet;
use BoardSim\Domain\Regulation\GuardResolver;
use BoardSim\Domain\Regulation\RegulatoryEvaluator;
use BoardSim\Domain\Regulation\RegulatoryStatus;
use BoardSim\Domain\Scoring\Dimension;
use BoardSim\Domain\Scoring\DimensionComparison;
use BoardSim\Domain\Scoring\FiringBuilder;
use BoardSim\Domain\Scoring\OptionLookup;
use BoardSim\Infrastructure\Release;

/**
 * Server-authoritative run orchestration.
 *
 * Everything that determines a score happens here, on the server. Run-specific
 * operations require every release pin to match the loaded deployment; a historical
 * run is never silently interpreted through different artifacts.
 *
 * A run pins five hashes at creation and never re-derives them:
 *   policy_sha256             scoring ruleset + weights + board rubric
 *   regulatory_bundle_sha256  instruments, predicates, facts, guards
 *   content_sha256            scenario versions + option-to-rule remaps
 *   difficulty_views_sha256   difficulty projection + board-field selection
 *   state_sha256              chained state, recomputed on every transition
 */
final class RunService
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';
    private const SUPPORTED_DIFFICULTIES = ['executive', 'leadership'];

    private readonly FiringBuilder $builder;
    private readonly RegulatoryEvaluator $evaluator;
    private readonly OptionLookup $options;
    private readonly \BoardSim\Content\DifficultyViews $views;
    private readonly \BoardSim\Content\BoardChallenges $challenges;
    private readonly \BoardSim\Content\RetentionPolicy $retention;
    private readonly \BoardSim\Domain\Share\ShareStore $shareStore;

    /** @var array<string, self> */
    private array $releaseContexts = [];

    /**
     * @param array<string, mixed> $regulatory OverlayLoader::load()
     * @param array<string, mixed> $policy     ScoringPolicyLoader::load()
     * @param array<string, mixed> $content    PinnedScenarios::loadAll()
     */
    public function __construct(
        private readonly RunStore $store,
        private readonly array $regulatory,
        private readonly array $policy,
        private readonly array $content,
        private readonly string $contentVersion,
        private readonly string $contentSha256,
        private readonly array $integrityControls = ['hash_chain'],
        ?\BoardSim\Content\DifficultyViews $views = null,
        ?\BoardSim\Content\BoardChallenges $challenges = null,
        private readonly ?string $projectRoot = null,
        private readonly ?\BoardSim\Content\RetentionPolicy $retentionPolicy = null,
        ?\BoardSim\Domain\Share\ShareStore $shareStore = null,
    ) {
        $this->views = $views ?? \BoardSim\Content\DifficultyViews::strictFallback();
        $this->challenges = $challenges ?? \BoardSim\Content\BoardChallenges::strictFallback();

        // No invented fallback: retention values are a decision, so a service
        // without them refuses to start rather than guessing how long to keep
        // someone's data.
        if ($retentionPolicy !== null) {
            $this->retention = $retentionPolicy;
        } elseif ($projectRoot !== null) {
            $this->retention = \BoardSim\Content\RetentionPolicy::load($projectRoot);
        } else {
            throw new \RuntimeException('RunService requires a retention policy or a project root');
        }

        // Created once per service, not per share() call. A fresh store per call
        // would mint a token and then fail to find it — the same class of bug as a
        // stale snapshot. An in-memory default is fine for one instance; a
        // deployment must inject a durable store, because PHP does not keep it
        // between requests.
        $this->shareStore = $shareStore ?? new \BoardSim\Domain\Share\InMemoryShareStore();
        $this->options = new OptionLookup($content);
        $this->evaluator = new RegulatoryEvaluator();
        $this->builder = new FiringBuilder(
            $this->options,
            $policy['ruleset'],
            new GuardResolver(
                $regulatory['rule_definitions'],
                $regulatory['guard_bindings'],
                $regulatory['reporting_instrument_ids']
            )
        );
    }

    /**
     * Deployment status, not just artefact pins.
     *
     * A deployment that is copied onto a host with no shell has no installer to
     * run and no way to ask "did the schema get created?". This endpoint is the
     * answer: it reports the release stamp, whether the database is reachable,
     * whether the schema is complete and which migrations were applied, and
     * whether the three pinned bundles are present.
     *
     * `status` is `ok` only when a durable store reports a complete schema and
     * all three bundles. Development and file stores report `schema: n/a`, which
     * is honest rather than a passing grade for a database that was never
     * involved.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $deployment = [
            'release' => $this->projectRoot !== null
                ? Release::load($this->projectRoot)
                : ['version' => 'unreleased', 'sha256' => null, 'built_at' => null, 'commit' => null],
            'database' => ['reachable' => true, 'schema' => 'n/a', 'applied' => [], 'error' => null],
            'bundles' => ['seeded' => null, 'expected' => null, 'missing' => []],
        ];

        $ready = true;

        if ($this->store instanceof StoreDiagnostics) {
            $database = $this->store->describe();
            $hashes = [
                (string) $this->policy['policy_sha256'],
                (string) $this->regulatory['bundle_sha256'],
                $this->contentSha256,
            ];

            $missing = $database['reachable'] ? $this->store->missingBundles($hashes) : $hashes;

            $deployment['database'] = $database;
            $deployment['bundles'] = [
                'seeded' => count($hashes) - count($missing),
                'expected' => BundleSeeder::EXPECTED,
                'missing' => $missing,
            ];

            if (!$database['reachable']) {
                $deployment['database']['hint'] = 'The database is not reachable. Check config.local.php '
                    . 'in the backend directory, then run bin/migrate.php from cron.';
            } elseif ($database['schema'] !== 'ok') {
                $deployment['database']['hint'] = 'Schema is not complete. Run bin/migrate.php from cron, '
                    . 'or apply dist/install.sql in phpMyAdmin.';
            } elseif ($missing !== []) {
                $deployment['database']['hint'] = 'The pinned bundles are not recorded. '
                    . 'Run bin/migrate.php from cron; it seeds them idempotently.';
            }

            $ready = $database['reachable'] && $database['schema'] === 'ok' && $missing === [];
        }

        return [
            'status' => $ready ? 'ok' : 'degraded',
            'ruleset_version' => $this->policy['ruleset_version'],
            'policy_sha256' => $this->policy['policy_sha256'],
            'regulatory_bundle_sha256' => $this->regulatory['bundle_sha256'],
            'content_version' => $this->contentVersion,
            'content_sha256' => $this->contentSha256,
            'difficulty_views_version' => $this->views->version(),
            'difficulty_views_sha256' => $this->views->sha256(),
            'scenarios' => count($this->content),
            'integrity_controls' => $this->integrityControls,
            'deployment' => $deployment,
        ];
    }

    /**
     * The in-product glossary: dimensions, rules, instruments, settings and
     * statuses, derived from the same pinned artefacts that score a run.
     *
     * It reads no database and computes no score, so it answers on a deployment
     * whose schema is not migrated yet — exactly when an operator needs it.
     *
     * @return array<string, mixed>
     */
    public function glossary(): array
    {
        if ($this->projectRoot === null) {
            throw new \RuntimeException('The glossary requires a project root');
        }

        return Glossary::build($this->projectRoot, $this->policy, $this->regulatory, $this->views);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $scenarioId = InputValidator::scenarioId($input['scenario_id'] ?? null);

        if (!isset($this->content[$scenarioId])) {
            throw DomainError::notFound('Scenario', $scenarioId);
        }

        $difficulty = InputValidator::difficulty($input['difficulty'] ?? null);

        // Difficulty is a projection, not a story. An unavailable view is a 422
        // with the reason from the policy, so the client and the copy cannot drift.
        if (!$this->views->has($difficulty)) {
            throw DomainError::invalid("Unknown difficulty '{$difficulty}'");
        }

        $view = $this->views->get($difficulty);

        if ($view['available'] !== true) {
            throw DomainError::unsupported((string) ($view['unavailable_reason'] ?? 'Unavailable'));
        }

        $appetite = InputValidator::riskAppetite($input['risk_appetite'] ?? null);

        if (!array_key_exists($appetite, $this->policy['appetite'])) {
            throw DomainError::invalid("Unknown risk appetite '{$appetite}'");
        }

        $persona = InputValidator::boardPersona($input['board_persona'] ?? null);

        if (!in_array($persona, $this->policy['personas'], true)) {
            throw DomainError::invalid("Unknown board persona '{$persona}'");
        }

        $date = InputValidator::simulationDate($input['date_of_simulation'] ?? null, gmdate('Y-m-d'));

        $run = [
            'run_id' => self::uuid4(),
            'scenario_id' => $scenarioId,
            'scenario_version' => (string) ($this->content[$scenarioId]['version'] ?? ''),
            'ruleset_version' => (string) $this->policy['ruleset_version'],
            'policy_sha256' => (string) $this->policy['policy_sha256'],
            'regulatory_bundle_sha256' => (string) $this->regulatory['bundle_sha256'],
            'content_version' => $this->contentVersion,
            'content_sha256' => $this->contentSha256,
            'difficulty_views_version' => $this->views->version(),
            'difficulty_views_sha256' => $this->views->sha256(),
            'difficulty' => $difficulty,
            'risk_appetite' => $appetite,
            'board_persona' => $persona,
            'date_of_simulation' => $date,
            'state' => RunState::Created->value,
            'current_stage' => null,
            'state_sha256' => self::GENESIS,
            'score' => null,
            'dimensions' => [],
            'board_sha256' => null,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'finalized_at' => null,
        ];

        $run['state_sha256'] = $this->stateHash($run);

        $run = $this->transition($run, RunState::ProfileLocked, false);
        $run['current_stage'] = 'S1';
        $run = $this->transition($run, RunState::Stage1Open, false);

        $this->store->transaction(function () use ($run): void {
            // Bundles first: runs.policy_sha256 has a foreign key to
            // content_bundles.bundle_sha256, so creating the run before recording
            // the bundle it pins fails on a fresh database.
            $this->pinBundles();
            $this->store->create($run);
        });

        return $this->runView($run);
    }

    /**
     * Accept exactly one decision per stage. Repeating the same idempotency key
     * returns the recorded event without re-scoring (07_ACCEPTANCE_TESTS.md).
     *
     * @return array<string, mixed>
     */
    public function decide(string $runId, string $key, string $stageId, string $choiceId): array
    {
        $runId = InputValidator::runId($runId);
        $key = InputValidator::idempotencyKey($key);
        $stageId = InputValidator::stageId($stageId);
        $choiceId = InputValidator::choiceId($choiceId);

        $context = $this->contextForRunId($runId);

        if ($context !== $this) {
            return $context->decide($runId, $key, $stageId, $choiceId);
        }

        return $this->store->transaction(function () use ($runId, $key, $stageId, $choiceId): array {
            $run = $this->store->findForUpdate($runId);

            if ($run === null) {
                throw DomainError::notFound('Run', $runId);
            }

            $this->assertCompatibleRelease($run);

            $requestSha256 = $this->decisionRequestHash($runId, $stageId, $choiceId);

            // Check idempotency only after the row lock is held. A retry that
            // waited behind the original request then observes both its event and
            // the committed post-decision run state.
            $existing = $this->store->eventByIdempotencyKey($runId, $key);

            if ($existing !== null) {
                if (!hash_equals((string) ($existing['request_sha256'] ?? ''), $requestSha256)) {
                    throw DomainError::conflict(
                        'The idempotency key was already used for a different decision payload'
                    );
                }

                return [
                    'replayed' => true,
                    'event' => $existing,
                    'run' => $this->runView($run),
                ];
            }

            $expected = RunState::openFor($stageId);

            if ($run['state'] !== $expected->value) {
                throw DomainError::conflict(
                    "Stage {$stageId} is not open; run is in {$run['state']}"
                );
            }

            if ($run['current_stage'] !== $stageId) {
                throw DomainError::conflict("Run is at {$run['current_stage']}, not {$stageId}");
            }

            // Validate the choice before the scoring layer sees it, so an unknown
            // option becomes a 400 rather than escaping as an internal error.
            $option = $this->option($run['scenario_id'], $stageId, $choiceId);

            $statuses = $this->statuses($run, $stageId);
            $decision = $this->builder->forDecision($run['scenario_id'], $stageId, $choiceId, $statuses);

            $ruleIds = array_map(static fn (array $firing): string => $firing['rule']->id, $decision['firings']);
            $events = $this->store->events($runId);
            $sequence = count($events) + 1;
            $prev = $events === [] ? self::GENESIS : (string) $events[count($events) - 1]['event_sha256'];
            $pre = (string) $run['state_sha256'];

            $event = [
                'run_id' => $runId,
                'sequence' => $sequence,
                'idempotency_key' => $key,
                'request_sha256' => $requestSha256,
                'stage_id' => $stageId,
                'option_id' => $choiceId,
                'option_label' => (string) ($option['label'] ?? ''),
                'effects' => (array) ($option['effects'] ?? []),
                'content_sha256' => (string) $run['content_sha256'],
                'rule_ids' => $ruleIds,
                'metric_delta' => $this->sumDeltas($decision['firings'], $run['risk_appetite']),
                'suppressed' => $decision['suppressed'],
                'visible_fact_ids' => $this->factIds($run, $stageId),
                'unknown_fact_ids' => $this->unknownFactIds($run, $stageId),
                'regulatory_status' => $this->statusSnapshot($statuses),
                'prev_event_sha256' => $prev,
                'pre_state_sha256' => $pre,
                'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];

            // Intermediate states are part of the state machine, but they are not
            // independently durable. Persisting only the final state keeps the run
            // and its event indivisible if any later write fails.
            $run = $this->transition($run, $expected->advance(), false);
            $next = $this->nextStage($stageId);
            $run['current_stage'] = $next;
            $run = $this->transition(
                $run,
                $next === null ? RunState::BoardOpen : RunState::openFor($next),
                false
            );

            $event['post_state_sha256'] = (string) $run['state_sha256'];
            $event['event_sha256'] = $this->eventHash($event);

            $this->store->appendEvent($event);
            $this->store->update($run);

            return [
                'replayed' => false,
                'event' => $event,
                'run' => $this->runView($run),
                'consequence' => $option['effects'] ?? [],
            ];
        });
    }

    /**
     * Board pack: structured selections only. Prose is never accepted here, so it
     * can never influence a score.
     *
     * @param array<string, string> $selections
     *
     * @return array<string, mixed>
     */
    public function board(string $runId, array $selections): array
    {
        $runId = InputValidator::runId($runId);
        $selections = InputValidator::boardSelections($selections);

        $context = $this->contextForRunId($runId);

        if ($context !== $this) {
            return $context->board($runId, $selections);
        }

        return $this->store->transaction(function () use ($runId, $selections): array {
            $run = $this->store->findForUpdate($runId);

            if ($run === null) {
                throw DomainError::notFound('Run', $runId);
            }

            $this->assertCompatibleRelease($run);

            if ($run['state'] !== RunState::BoardOpen->value) {
                throw DomainError::conflict("Board pack is not open; run is in {$run['state']}");
            }

            // Only the fields this difficulty collects may fire. Scoring an executive
            // run against all seven board rules would silently inflate it.
            $fields = $this->views->filterFields((string) $run['difficulty'], $this->policy['board_fields']);

            try {
                $boardResult = $this->builder->forBoard($selections, $fields);
            } catch (DomainError $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                // Missing or unknown selections are a client error, not a fault.
                throw DomainError::invalid($e->getMessage());
            }

            $result = $this->policy['calculator']->calculate(
                $this->reconstructFirings($runId, $boardResult['firings']),
                $run['risk_appetite']
            );

            $run['score'] = (int) $result['score'];
            $run['dimensions'] = $result['dimensions'];
            $run['board_sha256'] = CanonicalJson::sha256($selections);
            $run = $this->transition($run, RunState::BoardLocked, false);
            $run['current_stage'] = null;
            $run = $this->transition($run, RunState::Scored, false);
            $run['finalized_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $run = $this->transition($run, RunState::Finalized, false);

            $this->store->saveBoard($runId, $selections);
            $this->store->update($run);

            return [
                'run' => $this->runView($run),
                'score' => $run['score'],
                'label' => (string) $result['label'],
                'dimensions' => $run['dimensions'],
                'declined' => $boardResult['declined'],
            ];
        });
    }

    /**
     * Deterministic explanation. Rebuilt from the stored event log, never from a
     * fresh evaluation, so what the user is shown equals what was scored.
     *
     * @return array<string, mixed>
     */
    public function explain(string $runId): array
    {
        $context = $this->contextForRunId($runId);

        if ($context !== $this) {
            return $context->explain($runId);
        }

        $run = $this->requireRun($runId);

        if (!in_array($run['state'], [RunState::Scored->value, RunState::Finalized->value], true)) {
            throw DomainError::conflict('Run is not scored yet');
        }

        $selections = $this->store->boardSelections($runId);
        $fields = $this->views->filterFields((string) $run['difficulty'], $this->policy['board_fields']);
        $boardResult = $this->builder->forBoard($selections, $fields);
        $result = $this->policy['calculator']->calculate(
            $this->reconstructFirings($runId, $boardResult['firings']),
            $run['risk_appetite']
        );
        $scoreVerification = $this->scoreVerification($run, $result);
        $events = $this->store->events($runId);

        return [
            'run_id' => $runId,
            'pins' => $this->pins($run),
            // The machine-readable contract in 05_EXPLANATIONS_AND_METHOD.md names
            // these keys directly, so they are emitted alongside the richer ones
            // rather than left for the client to derive.
            'scenario' => (string) $run['scenario_id'],
            'ruleset_version' => (string) $run['ruleset_version'],
            'weighted_numerator' => (int) $result['numerator'],
            'rounding_expression' => sprintf('floor((%d+50)/100)', (int) $result['numerator']),
            'score' => (int) $run['score'],
            'stored_score' => (int) $run['score'],
            'reconstructed_score' => (int) $result['score'],
            'stored_dimensions' => (array) $run['dimensions'],
            'reconstructed_dimensions' => (array) $result['dimensions'],
            'score_verified' => $scoreVerification['verified'],
            'score_verification' => $scoreVerification,
            'label' => (string) $result['label'],
            'dimensions' => $result['dimensions'],
            'weights' => $result['weights'],
            'contributions' => $result['contributions'],
            'numerator' => (int) $result['numerator'],
            'rounding' => (string) $result['rounding'],
            'trace' => $result['trace'],
            'board_declined' => $boardResult['declined'],
            // The business consequences of each decision, quoted from the same
            // authored `effects` the player saw at decision time. 05 asks the
            // explanation to repeat them rather than leave the reader to
            // reconstruct what the choice actually did.
            'consequences' => $this->consequences($events),
            'legal' => [
                'assessment' => 'A separate applicability assessment. It never alters the score.',
                'instruments' => $this->legalPanel($run),
            ],
            'events' => array_map(
                static fn (array $event): array => [
                    'sequence' => $event['sequence'],
                    'stage_id' => $event['stage_id'],
                    'option_id' => $event['option_id'],
                    'rule_ids' => $event['rule_ids'],
                    'metric_delta' => $event['metric_delta'],
                    'suppressed' => $event['suppressed'],
                    'regulatory_status' => $event['regulatory_status'],
                    'event_sha256' => $event['event_sha256'],
                ],
                $events
            ),
            'chain' => $this->verifyChain($runId),
            'governance' => [
                'source_kind' => 'SIMULATION_ASSUMPTION',
                'counsel_reviewed' => false,
                'psychometrically_calibrated' => false,
            ],
        ];
    }

    /**
     * The consequences of each recorded decision, as authored.
     *
     * Read from the immutable event snapshot rather than the currently deployed
     * scenario content. `effects` is an explicitly non-scoring teaching ledger
     * (02_SCORING_MODEL.md), but snapshotting and hashing it preserves exactly
     * what accompanied the recorded choice.
     *
     * @param array<string, mixed> $run
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function consequences(array $events): array
    {
        $out = [];

        foreach ($events as $event) {
            $out[] = [
                'stage_id' => (string) ($event['stage_id'] ?? ''),
                'option_id' => (string) ($event['option_id'] ?? ''),
                'decision' => (string) ($event['option_label'] ?? ''),
                'effects' => (array) ($event['effects'] ?? []),
                'regulatory_status' => $event['regulatory_status'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * "What would change it?" — the exact effect of each unchosen option.
     *
     * Computed by substitution and full replay on the release matching the run, never by
     * back-solving from the outcome. Nothing here is persisted, and no rule id or
     * delta is guessed: both come from the same builder that scored the run.
     *
     * Only the option is substituted. A release mismatch is rejected before this
     * method runs, so alternatives are never calculated against different artifacts.
     *
     * @return array<string, mixed>
     */
    public function counterfactuals(string $runId): array
    {
        $context = $this->contextForRunId($runId);

        if ($context !== $this) {
            return $context->counterfactuals($runId);
        }

        $run = $this->requireRun($runId);

        if (!in_array($run['state'], [RunState::Scored->value, RunState::Finalized->value], true)) {
            throw DomainError::conflict('Alternatives are available only after the run is scored');
        }

        $stages = ['S1', 'S2', 'S3'];
        $path = [];

        foreach ($this->store->events($runId) as $event) {
            $path[(string) $event['stage_id']] = (string) $event['option_id'];
        }

        foreach ($stages as $stage) {
            if (!isset($path[$stage])) {
                throw DomainError::conflict("Stage {$stage} was never answered");
            }
        }

        $selections = $this->store->boardSelections($runId);
        $fields = $this->views->filterFields((string) $run['difficulty'], $this->policy['board_fields']);
        $boardResult = $this->builder->forBoard($selections, $fields);
        $scenarioId = (string) $run['scenario_id'];

        // Authored facts are stage-scoped and never depend on earlier choices, so
        // each stage keeps the same regulatory status in every counterfactual.
        // The identity check below is what makes that assumption safe: if facts
        // ever became choice-dependent, the replay would stop reproducing the
        // recorded score and this method would refuse to answer.
        $statuses = [];

        foreach ($stages as $stage) {
            $statuses[$stage] = $this->statuses($run, $stage);
        }

        $evaluate = function (array $candidate) use ($stages, $statuses, $boardResult, $run, $scenarioId): array {
            $firings = [];
            $perStage = [];

            foreach ($stages as $stage) {
                $decision = $this->builder->forDecision(
                    $scenarioId,
                    $stage,
                    $candidate[$stage],
                    $statuses[$stage]
                );

                $ids = [];

                foreach ($decision['firings'] as $firing) {
                    $ids[] = (string) $firing['rule']->id;
                    $firings[] = ['stage' => $stage, 'rule' => $firing['rule']];
                }

                $perStage[$stage] = $ids;
            }

            return [
                'result' => $this->policy['calculator']->calculate(
                    array_merge($firings, $boardResult['firings']),
                    (string) $run['risk_appetite']
                ),
                'rules' => $perStage,
            ];
        };

        $actual = $evaluate($path);

        if ((int) $actual['result']['score'] !== (int) $run['score']) {
            throw DomainError::internal(
                'Counterfactual replay does not reproduce the recorded score; '
                . 'refusing to report alternatives'
            );
        }

        $stagesOut = [];

        foreach ($stages as $stage) {
            $alternatives = [];

            foreach ($this->scenarioOptionIds($scenarioId, $stage) as $optionId) {
                if ($optionId === $path[$stage]) {
                    continue;
                }

                $candidate = $path;
                $candidate[$stage] = $optionId;
                $evaluation = $evaluate($candidate);

                $dimensionDelta = [];

                foreach ((array) $evaluation['result']['dimensions'] as $code => $value) {
                    $difference = (int) $value - (int) $run['dimensions'][(string) $code];

                    if ($difference !== 0) {
                        $dimensionDelta[(string) $code] = $difference;
                    }
                }

                $scoreDelta = (int) $evaluation['result']['score'] - (int) $run['score'];
                $added = array_values(array_diff($evaluation['rules'][$stage], $actual['rules'][$stage]));
                $removed = array_values(array_diff($actual['rules'][$stage], $evaluation['rules'][$stage]));
                $alternativeLabel = (string) $this->option($scenarioId, $stage, $optionId)['label'];
                $chosenLabel = (string) $this->option($scenarioId, $stage, $path[$stage])['label'];

                $alternatives[] = [
                    'option_id' => $optionId,
                    'label' => $alternativeLabel,
                    'score' => (int) $evaluation['result']['score'],
                    'score_delta' => $scoreDelta,
                    'label_for_score' => (string) $evaluation['result']['label'],
                    'dimension_delta' => $dimensionDelta,
                    'rules_added' => $added,
                    'rules_removed' => $removed,
                    'summary' => $this->counterfactualSummary(
                        $stage,
                        $chosenLabel,
                        $alternativeLabel,
                        $scoreDelta,
                        $dimensionDelta,
                        $added,
                        $removed
                    ),
                ];
            }

            $stagesOut[] = [
                'stage' => $stage,
                'chosen_option_id' => $path[$stage],
                'chosen_label' => (string) $this->option($scenarioId, $stage, $path[$stage])['label'],
                'alternatives' => $alternatives,
            ];
        }

        return [
            'run_id' => $runId,
            'pins' => $this->pins($run),
            'score' => (int) $run['score'],
            'dimensions' => $run['dimensions'],
            'stages' => $stagesOut,
            'note' => 'Alternatives substitute one authored option and replay the whole run against the '
                . 'policy currently loaded by this deployment. Policy changes are a separate ruleset version, but '
                . 'counterfactual. A later outcome never implies the original choice was wrong.',
            'governance' => [
                'source_kind' => 'SIMULATION_ASSUMPTION',
                'counsel_reviewed' => false,
                'psychometrically_calibrated' => false,
            ],
        ];
    }

    /**
     * One deterministic sentence per alternative, built from the arithmetic so it
     * can never disagree with the numbers printed beside it.
     *
     * @param array<string, int> $dimensionDelta
     * @param list<string>        $rulesAdded
     * @param list<string>        $rulesRemoved
     */
    private function counterfactualSummary(
        string $stage,
        string $chosenLabel,
        string $alternativeLabel,
        int $scoreDelta,
        array $dimensionDelta,
        array $rulesAdded,
        array $rulesRemoved,
    ): string {
        $direction = match (true) {
            $scoreDelta > 0 => "{$scoreDelta} higher",
            $scoreDelta < 0 => abs($scoreDelta) . ' lower',
            default => 'unchanged',
        };

        $sentence = sprintf(
            'Choosing "%s" instead of "%s" at %s scores %s.',
            $alternativeLabel,
            $chosenLabel,
            $stage,
            $direction
        );

        sort($rulesAdded);
        sort($rulesRemoved);
        $parts = [];

        if ($rulesAdded !== []) {
            $parts[] = 'adds ' . implode(', ', $rulesAdded);
        }

        if ($rulesRemoved !== []) {
            $parts[] = 'drops ' . implode(', ', $rulesRemoved);
        }

        if ($parts !== []) {
            $sentence .= ' It ' . implode(' and ', $parts) . ' for that stage.';
        }

        if ($dimensionDelta !== []) {
            ksort($dimensionDelta);
            $terms = [];

            foreach ($dimensionDelta as $code => $value) {
                $terms[] = sprintf('%s %+d', (string) $code, (int) $value);
            }

            $sentence .= ' Dimensions: ' . implode(', ', $terms) . '.';
        }

        return $sentence;
    }

    /**
     * @return list<string>
     */
    private function scenarioOptionIds(string $scenarioId, string $stageId): array
    {
        foreach ((array) ($this->content[$scenarioId]['stages'] ?? []) as $stage) {
            if ((string) ($stage['id'] ?? '') !== $stageId) {
                continue;
            }

            $ids = [];

            foreach ((array) ($stage['options'] ?? []) as $option) {
                $ids[] = (string) ($option['id'] ?? '');
            }

            return $ids;
        }

        throw DomainError::internal("Scenario {$scenarioId} has no stage {$stageId}");
    }

    /**
     * @param list<string> $runIds
     *
     * @return array<string, mixed>
     */
    public function shareCreate(array $runIds, ?string $nowUtc = null): array
    {
        return $this->share()->create($runIds, $nowUtc);
    }

    /**
     * @return array<string, mixed>
     */
    public function shareResolve(string $token, ?string $nowUtc = null): array
    {
        return $this->share()->resolve($token, $nowUtc);
    }

    /**
     * @return array<string, mixed>
     */
    public function shareRevoke(string $token, ?string $nowUtc = null): array
    {
        return $this->share()->revoke($token, $nowUtc);
    }

    private function share(): \BoardSim\Domain\Share\ShareService
    {
        return new \BoardSim\Domain\Share\ShareService(
            $this,
            $this->shareStore,
            $this->retention
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function retention(): array
    {
        return $this->retention->describe();
    }

    /**
     * The retention job's one operation. Physically deletes everything past the
     * ceiling and reports exactly what went, so a cron log is an audit trail.
     *
     * @return array<string, mixed>
     */
    public function purgeExpired(?string $nowUtc = null): array
    {
        $cutoff = $this->retention->cutoff($nowUtc);
        $purged = $this->store->purgeBefore($cutoff);

        return [
            'cutoff_utc' => $cutoff,
            'purged' => count($purged),
            'run_ids' => $purged,
            'retention_days' => $this->retention->runRetentionDays(),
            'note' => 'Runs past the retention ceiling were physically deleted, not flagged. '
                . 'Their event logs no longer exist to verify, which is what deletion means.',
        ];
    }

    /**
     * Erase a run permanently: the run, its event log and its board selections.
     *
     * 06_INTERFACE_AND_ACCESSIBILITY.md requires deletion, and the audit trail is
     * worth less than the user's right to erasure, so this is a physical delete
     * rather than a hidden flag. The consequence is stated in the response and in
     * the UI: after this, the run cannot be replayed or verified *because it no
     * longer exists*, and any profile that included it will recompute without it.
     *
     * Possession of the run id is the capability. Run ids are random UUIDs and are
     * never listed by any endpoint, so the same bearer model applies here as to a
     * share link — documented rather than pretended away.
     *
     * @return array<string, mixed>
     */
    public function delete(string $runId): array
    {
        // Erasure must remain available even when the run's historical release is
        // unavailable; release compatibility protects interpretation, not deletion.
        $runId = InputValidator::runId($runId);
        $run = $this->store->find($runId);

        if ($run === null) {
            throw DomainError::notFound('Run', $runId);
        }
        $events = $this->store->delete($runId);

        return [
            'run_id' => $runId,
            'scenario_id' => (string) $run['scenario_id'],
            'deleted' => true,
            'events_removed' => $events,
            'recoverable' => false,
            'note' => 'The run, its event log and its board selections were physically deleted. '
                . 'It cannot be replayed or verified, because it no longer exists. Any profile that '
                . 'included it will be recomputed from the runs that remain.',
        ];
    }

    /**
     * Public demonstration. See `DemoService` for why this cannot leak a score.
     *
     * @return array<string, mixed>
     */
    public function demoStart(): array
    {
        return $this->demo()->start();
    }

    /**
     * @return array<string, mixed>
     */
    public function demoDecide(string $stageId, string $choiceId): array
    {
        return $this->demo()->decide(
            InputValidator::stageId($stageId),
            InputValidator::choiceId($choiceId)
        );
    }

    private function demo(): \BoardSim\Domain\Demo\DemoService
    {
        return new \BoardSim\Domain\Demo\DemoService(
            $this->policy['ruleset'],
            (string) $this->policy['ruleset_version'],
            $this->projectRoot === null
                ? \BoardSim\Content\DemoScenario::unavailable()
                : \BoardSim\Content\DemoScenario::load($this->projectRoot)
        );
    }

    /**
     * Latest regulatory position per instrument, with a resolvable reference.
     *
     * @param array<string, mixed> $run
     *
     * @return list<array{anchor: string, status: string, reference: string}>
     */
    private function legalPanel(array $run): array
    {
        $panel = [];

        foreach ($this->statuses($run, 'S3') as $anchor => $status) {
            $panel[] = [
                'anchor' => (string) $anchor,
                'status' => $status->value,
                'reference' => '/v1/refs/' . $anchor,
            ];
        }

        return $panel;
    }

    /**
     * Recompute the hash chain and report the first divergence, if any.
     *
     * @return array<string, mixed>
     */
    public function verifyChain(string $runId): array
    {
        $context = $this->contextForRunId($runId);

        if ($context !== $this) {
            return $context->verifyChain($runId);
        }

        $run = $this->requireRun($runId);
        $runId = (string) $run['run_id'];
        $events = $this->store->events($runId);
        $previous = self::GENESIS;
        $previousPostState = null;

        foreach ($events as $index => $event) {
            if ((string) $event['prev_event_sha256'] !== $previous) {
                return ['ok' => false, 'broken_at' => $index + 1, 'reason' => 'prev_event_sha256 mismatch'];
            }

            if ($this->eventHash($event) !== (string) $event['event_sha256']) {
                return ['ok' => false, 'broken_at' => $index + 1, 'reason' => 'event_sha256 mismatch'];
            }

            if ($previousPostState !== null && (string) $event['pre_state_sha256'] !== $previousPostState) {
                return ['ok' => false, 'broken_at' => $index + 1, 'reason' => 'state transition mismatch'];
            }

            $previous = (string) $event['event_sha256'];
            $previousPostState = (string) $event['post_state_sha256'];
        }

        if (!hash_equals((string) $run['state_sha256'], $this->stateHash($run))) {
            return ['ok' => false, 'broken_at' => null, 'reason' => 'run state_sha256 mismatch'];
        }

        if (in_array($run['state'], [RunState::Scored->value, RunState::Finalized->value], true)) {
            $boardSha256 = CanonicalJson::sha256($this->store->boardSelections($runId));

            if (!hash_equals((string) ($run['board_sha256'] ?? ''), $boardSha256)) {
                return ['ok' => false, 'broken_at' => null, 'reason' => 'board selections hash mismatch'];
            }
        }

        $scoreVerified = null;

        if (in_array($run['state'], [RunState::Scored->value, RunState::Finalized->value], true)) {
            $verification = $this->reconstructedScoreVerification($run);
            $scoreVerified = $verification['verified'];

            if (!$scoreVerified) {
                $reason = !$verification['score_matches']
                    ? 'stored score reconstruction mismatch'
                    : 'stored dimension reconstruction mismatch';

                return [
                    'ok' => false,
                    'broken_at' => null,
                    'reason' => $reason,
                    'score_verified' => false,
                    'score_verification' => $verification,
                ];
            }
        }

        return [
            'ok' => true,
            'events' => count($events),
            'head' => $previous,
            'run_state_verified' => true,
            'score_verified' => $scoreVerified,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRun(string $runId): array
    {
        $runId = InputValidator::runId($runId);
        $run = $this->store->find($runId);

        if ($run === null) {
            throw DomainError::notFound('Run', $runId);
        }

        $this->assertCompatibleRelease($run);

        return $run;
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array<string, mixed>
     */
    private function transition(array $run, RunState $to, bool $persist = true): array
    {
        $run['state'] = $to->value;
        $run['state_sha256'] = $this->stateHash($run);

        if ($persist) {
            $this->store->update($run);
        }

        return $run;
    }

    /**
     * @param array<string, mixed> $run
     */
    private function stateHash(array $run): string
    {
        return CanonicalJson::sha256([
            'run_id' => $run['run_id'],
            'scenario_id' => $run['scenario_id'],
            'scenario_version' => $run['scenario_version'],
            'ruleset_version' => $run['ruleset_version'],
            'policy_sha256' => $run['policy_sha256'],
            'regulatory_bundle_sha256' => $run['regulatory_bundle_sha256'],
            'content_version' => $run['content_version'],
            'content_sha256' => $run['content_sha256'],
            'difficulty_views_version' => $run['difficulty_views_version'],
            'difficulty_views_sha256' => $run['difficulty_views_sha256'],
            'difficulty' => $run['difficulty'],
            'risk_appetite' => $run['risk_appetite'],
            'board_persona' => $run['board_persona'],
            'date_of_simulation' => $run['date_of_simulation'],
            'state' => $run['state'],
            'current_stage' => $run['current_stage'],
            'score' => $run['score'],
            'dimensions' => $run['dimensions'],
            'board_sha256' => $run['board_sha256'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function eventHash(array $event): string
    {
        return CanonicalJson::sha256([
            'run_id' => $event['run_id'],
            'sequence' => $event['sequence'],
            'idempotency_key' => $event['idempotency_key'],
            'request_sha256' => $event['request_sha256'],
            'stage_id' => $event['stage_id'],
            'option_id' => $event['option_id'],
            'option_label' => $event['option_label'],
            'effects_json' => EvidenceJson::encode((array) $event['effects']),
            'content_sha256' => $event['content_sha256'],
            'rule_ids' => array_values(array_map('strval', (array) $event['rule_ids'])),
            'metric_delta' => $event['metric_delta'],
            'suppressed' => $event['suppressed'],
            'regulatory_status' => $event['regulatory_status'],
            'prev_event_sha256' => $event['prev_event_sha256'],
            'pre_state_sha256' => $event['pre_state_sha256'],
            'post_state_sha256' => $event['post_state_sha256'],
            'visible_fact_ids' => array_values(array_map('strval', (array) $event['visible_fact_ids'])),
            'unknown_fact_ids' => array_values(array_map('strval', (array) $event['unknown_fact_ids'])),
            'recorded_at' => $event['recorded_at'],
        ]);
    }

    /**
     * Board rubric for the client.
     *
     * The `substantiated` flag and its `reason` are withheld deliberately: they
     * are the answer key. The client renders the choices; only the server knows
     * which of them earns a rule.
     *
     * @return array<string, mixed>
     */
    public function boardRubric(string $difficulty = 'leadership'): array
    {
        $fields = [];

        try {
            $projection = $this->views->get($difficulty);
        } catch (\RuntimeException $e) {
            throw DomainError::invalid($e->getMessage());
        }

        if ($projection['available'] !== true) {
            throw DomainError::unsupported((string) ($projection['unavailable_reason'] ?? 'Unavailable'));
        }

        $allowed = $projection['board_fields'];

        foreach ($this->policy['board_fields'] as $key => $definition) {
            if (!in_array((string) $key, $allowed, true)) {
                continue;
            }

            $options = [];

            foreach ((array) ($definition['options'] ?? []) as $option) {
                $options[] = [
                    'id' => (string) ($option['id'] ?? ''),
                    'label' => (string) ($option['label'] ?? ''),
                ];
            }

            $fields[] = [
                'key' => (string) $key,
                'label' => (string) ($definition['label'] ?? ''),
                'prompt' => (string) ($definition['prompt'] ?? ''),
                'options' => $options,
            ];
        }

        return [
            'ruleset_version' => (string) $this->policy['ruleset_version'],
            'difficulty' => $difficulty,
            'field_count' => count($fields),
            'fields' => $fields,
            // Conversation prompts and a suggested clock. Neither is a scoring
            // input; both are attached here because the board room is where they
            // are shown, and keeping them off the scoring path is the point.
            'challenges' => $this->challenges->all(),
            'preparation' => $this->challenges->preparation(),
            'challenges_version' => $this->challenges->version(),
            'challenges_sha256' => $this->challenges->sha256(),
            'note' => 'Free-text answers are never graded. Only these structured selections '
                . 'can affect a score. Challenge cards are prompts for the conversation and '
                . 'the preparation timer is not scored.',
        ];
    }

    /**
     * Portfolio aggregation over completed runs.
     *
     * @param list<string> $runIds
     *
     * @return array<string, mixed>
     */
    public function profile(array $runIds): array
    {
        $runIds = InputValidator::runIdList($runIds);

        return (new \BoardSim\Domain\Profile\ProfileAggregator(
            $this->store,
            $this->content,
            $this->retention->runRetentionDays(),
            fn (array $run): bool => $this->isResolvableRelease($run)
        ))->aggregate($runIds);
    }

    /**
     * Difficulty catalogue for the client, including unavailable views so the
     * interface can render them disabled with an honest explanation.
     *
     * @return list<array<string, mixed>>
     */
    public function availableDifficulties(): array
    {
        $out = [];

        foreach ($this->views->all() as $key => $view) {
            $out[] = [
                'key' => (string) $key,
                'label' => (string) $view['label'],
                'available' => $view['available'],
                'time_estimate' => (string) $view['time_estimate'],
                'description' => (string) $view['description'],
                'board_field_count' => count($view['board_fields']),
                'unavailable_reason' => $view['unavailable_reason'],
            ];
        }

        return $out;
    }

    /**
     * Current run state, without scoring internals.
     *
     * @return array<string, mixed>
     */
    public function status(string $runId): array
    {
        $context = $this->contextForRunId($runId);

        if ($context !== $this) {
            return $context->status($runId);
        }

        return $this->runView($this->requireRun($runId));
    }

    /**
     * Scoped official source for a regulation or framework anchor.
     *
     * @return array<string, mixed>
     */
    public function reference(string $anchor): array
    {
        $anchor = InputValidator::reference($anchor);
        $instrument = $this->regulatory['instruments'][$anchor] ?? null;

        if ($instrument === null) {
            throw DomainError::notFound('Reference', $anchor);
        }

        return [
            'id' => $instrument->id,
            'title' => $instrument->title,
            'source_url' => $instrument->sourceUrl,
            'nature' => $instrument->nature,
            'verification_tier' => $instrument->verificationTier,
            'reporting_duty' => $instrument->reportingDuty,
            'counsel_signed_off' => $instrument->counselSignedOff,
            'related_urls' => $instrument->relatedUrls,
            'provenance' => [
                'source_kind' => $instrument->sourceKind,
                'counsel_reviewed' => $instrument->counselReviewed,
                'psychometrically_calibrated' => $instrument->psychometricallyCalibrated,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array<string, string>
     */
    private function pins(array $run): array
    {
        return [
            'ruleset_version' => (string) $run['ruleset_version'],
            'policy_sha256' => (string) $run['policy_sha256'],
            'regulatory_bundle_sha256' => (string) $run['regulatory_bundle_sha256'],
            'content_version' => (string) $run['content_version'],
            'content_sha256' => (string) $run['content_sha256'],
            'difficulty_views_version' => (string) ($run['difficulty_views_version'] ?? ''),
            'difficulty_views_sha256' => (string) ($run['difficulty_views_sha256'] ?? ''),
            'scenario_id' => (string) $run['scenario_id'],
            'scenario_version' => (string) $run['scenario_version'],
        ];
    }

    /**
     * Client-facing view. Option rule ids are never included: the specification
     * forbids pre-revealing which rules an option triggers.
     *
     * @param array<string, mixed> $run
     *
     * @return array<string, mixed>
     */
    private function runView(array $run): array
    {
        $viewed = [
            'run_id' => $run['run_id'],
            'pins' => $this->pins($run),
            'difficulty' => $run['difficulty'],
            'risk_appetite' => $run['risk_appetite'],
            'board_persona' => $run['board_persona'],
            'date_of_simulation' => $run['date_of_simulation'],
            'state' => $run['state'],
            'current_stage' => $run['current_stage'],
            'score' => $run['score'],
            'dimensions' => $run['dimensions'],
            'state_sha256' => $run['state_sha256'],
            'board_sha256' => $run['board_sha256'] ?? null,
        ];

        if (is_string($run['current_stage'])) {
            $projection = $this->views->get((string) $run['difficulty']);
            $projection['board_field_count'] = count($projection['board_fields']);
            $projection['difficulty_views_version'] = $this->views->version();
            $projection['difficulty_views_sha256'] = $this->views->sha256();

            $viewed['difficulty_view'] = $projection;
            $viewed['stage'] = $this->visibleStage($run, $run['current_stage']);
        }

        return $viewed;
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array<string, mixed>
     */
    private function visibleStage(array $run, string $stageId): array
    {
        $projection = $this->views->get((string) $run['difficulty']);

        foreach ((array) ($this->content[$run['scenario_id']]['stages'] ?? []) as $stage) {
            if ((string) ($stage['id'] ?? '') !== $stageId) {
                continue;
            }

            $options = [];

            foreach ((array) ($stage['options'] ?? []) as $option) {
                $entry = [
                    'id' => (string) ($option['id'] ?? ''),
                    'label' => (string) ($option['label'] ?? ''),
                ];

                // The condensed view omits the operational-effect hint, which is
                // presentation only: the authored option is unchanged.
                if ($projection['show_effects'] === true) {
                    $entry['effects'] = $option['effects'] ?? [];
                }

                $options[] = $entry;
            }

            return [
                'stage_id' => $stageId,
                'time' => (string) ($stage['time'] ?? ''),
                // The standing situation, so the first decision is made with the
                // context the scenario was authored around rather than a bare prompt.
                'briefing' => (string) ($this->content[$run['scenario_id']]['starting_briefing'] ?? ''),
                'inject' => (string) ($stage['question'] ?? ''),
                'is_primary' => $projection['primary_stage'] === $stageId,
                'reveal' => array_values((array) ($stage['reveal'] ?? [])),
                'stakeholder_pressure' => $projection['show_stakeholder_pressure'] === true
                    ? (string) ($this->content[$run['scenario_id']]['stakeholder_pressure'] ?? '')
                    : null,
                'options' => $options,
                'regulatory' => $this->statusSnapshot($this->statuses($run, $stageId)),
            ];
        }

        throw DomainError::internal("Scenario {$run['scenario_id']} has no stage {$stageId}");
    }

    /**
     * @return array<string, mixed>
     */
    private function option(string $scenarioId, string $stageId, string $optionId): array
    {
        try {
            return $this->options->option($scenarioId, $stageId, $optionId);
        } catch (\RuntimeException $e) {
            throw DomainError::invalid($e->getMessage());
        }
    }

    private function nextStage(string $stageId): ?string
    {
        return match ($stageId) {
            'S1' => 'S2',
            'S2' => 'S3',
            'S3' => null,
            default => throw DomainError::invalid("Unknown stage '{$stageId}'"),
        };
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return array<string, RegulatoryStatus>
     */
    private function statuses(array $run, string $stageId): array
    {
        $facts = FactSet::fromAuthored($this->factsFor($run), $stageId);
        $statuses = [];

        foreach ($this->regulatory['instruments'] as $id => $instrument) {
            $statuses[$id] = $this->evaluator
                ->evaluate($instrument, $facts, (string) $run['date_of_simulation'])['status'];
        }

        return $statuses;
    }

    /**
     * @param array<string, RegulatoryStatus> $statuses
     *
     * @return array<string, string>
     */
    private function statusSnapshot(array $statuses): array
    {
        $snapshot = [];

        foreach ($statuses as $id => $status) {
            $snapshot[$id] = $status->value;
        }

        return $snapshot;
    }

    /**
     * @param list<array{stage: string, rule: object}> $firings
     *
     * @return array<string, int>
     */
    private function sumDeltas(array $firings, string $appetite): array
    {
        $delta = array_fill_keys(Dimension::codes(), 0);
        $appetiteMap = (array) ($this->policy['appetite'][$appetite] ?? []);

        foreach ($firings as $firing) {
            $rule = $firing['rule'];

            foreach (Dimension::codes() as $code) {
                $delta[$code] += $rule->deltaFor($code);
            }

            $delta['RJ'] += (int) ($appetiteMap[$rule->id] ?? 0);
        }

        ksort($delta);

        return $delta;
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return list<array<string, mixed>>
     */
    private function factsFor(array $run): array
    {
        $facts = $this->regulatory['scenario_facts'][$run['scenario_id']]['facts'] ?? [];

        return is_array($facts) ? array_values($facts) : [];
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return list<string>
     */
    private function factIds(array $run, string $stageId): array
    {
        return FactSet::fromAuthored($this->factsFor($run), $stageId)->visibleFactIds();
    }

    /**
     * Facts that exist but are not yet visible. `unknown` must stay distinct from
     * `false`, so these are snapshotted rather than dropped.
     *
     * @param array<string, mixed> $run
     *
     * @return list<string>
     */
    private function unknownFactIds(array $run, string $stageId): array
    {
        $order = FactSet::STAGE_ORDER;
        $current = $order[$stageId] ?? 0;
        $ids = [];

        foreach ($this->factsFor($run) as $fact) {
            $stage = (string) ($fact['visible_from_stage'] ?? '');

            if (($order[$stage] ?? 0) > $current) {
                $ids[] = (string) ($fact['id'] ?? '');
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Rebuild the firings from the stored event log, so a score is always a
     * function of what was recorded rather than of a fresh evaluation.
     *
     * @param list<array{stage: string, rule: object}> $extra
     *
     * @return list<array{stage: string, rule: object}>
     */
    private function reconstructFirings(string $runId, array $extra): array
    {
        $firings = [];

        foreach ($this->store->events($runId) as $event) {
            foreach ((array) $event['rule_ids'] as $ruleId) {
                if (!$this->policy['ruleset']->has((string) $ruleId)) {
                    throw DomainError::internal(
                        "Stored event references rule {$ruleId}, absent from ruleset "
                        . $this->policy['ruleset_version']
                    );
                }

                $firings[] = [
                    'stage' => (string) $event['stage_id'],
                    'rule' => $this->policy['ruleset']->get((string) $ruleId),
                ];
            }
        }

        return array_merge($firings, $extra);
    }

    /**
     * Refuse to interpret a run through artifacts other than the ones it pins.
     *
     * @param array<string, mixed> $run
     */
    public function assertCompatibleRelease(array $run): void
    {
        $expected = [
            'ruleset_version' => (string) $this->policy['ruleset_version'],
            'policy_sha256' => (string) $this->policy['policy_sha256'],
            'regulatory_bundle_sha256' => (string) $this->regulatory['bundle_sha256'],
            'content_version' => $this->contentVersion,
            'content_sha256' => $this->contentSha256,
            'difficulty_views_version' => $this->views->version(),
            'difficulty_views_sha256' => $this->views->sha256(),
        ];

        foreach ($expected as $field => $value) {
            $actual = (string) ($run[$field] ?? '');

            if ($actual === '' || !hash_equals($value, $actual)) {
                throw DomainError::releaseUnavailable(
                    "Run {$run['run_id']} pins {$field}=" . ($actual === '' ? '(missing)' : $actual)
                    . "; this deployment provides {$value}"
                );
            }
        }

        $scenario = $this->content[(string) ($run['scenario_id'] ?? '')] ?? null;

        if (!is_array($scenario) || (string) ($scenario['version'] ?? '') !== (string) $run['scenario_version']) {
            throw DomainError::releaseUnavailable(
                "Run {$run['run_id']} pins a scenario version unavailable on this deployment"
            );
        }
    }

    /** @param array<string, mixed> $run */
    private function isCompatibleRelease(array $run): bool
    {
        try {
            $this->assertCompatibleRelease($run);

            return true;
        } catch (DomainError $e) {
            if ($e->errorCode === 'RUN_RELEASE_UNAVAILABLE') {
                return false;
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $run */
    private function isResolvableRelease(array $run): bool
    {
        try {
            $this->contextForRun($run);

            return true;
        } catch (DomainError $e) {
            if ($e->errorCode === 'RUN_RELEASE_UNAVAILABLE') {
                return false;
            }

            throw $e;
        }
    }

    private function contextForRunId(string $runId): self
    {
        $runId = InputValidator::runId($runId);
        $run = $this->store->find($runId);

        if ($run === null) {
            throw DomainError::notFound('Run', $runId);
        }

        return $this->contextForRun($run);
    }

    /**
     * Resolve the exact artifacts pinned by a run. Policy and scenario-content
     * versions retained in the repository are loaded by version and then verified
     * by hash. Regulatory and difficulty artifacts are reused only when the active
     * retained copies match the run exactly; otherwise interpretation fails closed.
     *
     * @param array<string, mixed> $run
     */
    private function contextForRun(array $run): self
    {
        if ($this->isCompatibleRelease($run)) {
            return $this;
        }

        if ($this->projectRoot === null) {
            throw DomainError::releaseUnavailable('Historical release loading requires a project root');
        }

        if (!hash_equals((string) $this->regulatory['bundle_sha256'], (string) ($run['regulatory_bundle_sha256'] ?? ''))) {
            throw DomainError::releaseUnavailable(
                "Run {$run['run_id']} requires regulatory artifacts not retained by this deployment"
            );
        }

        $key = implode('|', [
            (string) ($run['policy_sha256'] ?? ''),
            (string) ($run['content_sha256'] ?? ''),
            (string) ($run['regulatory_bundle_sha256'] ?? ''),
            (string) ($run['difficulty_views_sha256'] ?? ''),
        ]);

        if (isset($this->releaseContexts[$key])) {
            return $this->releaseContexts[$key];
        }

        try {
            $policy = (new ScoringPolicyLoader($this->projectRoot))->load((string) $run['ruleset_version']);
            $pinned = new PinnedScenarios($this->projectRoot, (string) $run['content_version']);
            $content = $pinned->loadAll();
            $views = \BoardSim\Content\DifficultyViews::load(
                $this->projectRoot,
                (string) $run['difficulty_views_version']
            );
        } catch (\Throwable $e) {
            throw DomainError::releaseUnavailable(
                "Run {$run['run_id']} requires historical artifacts that could not be loaded: {$e->getMessage()}"
            );
        }

        if (!hash_equals((string) $run['policy_sha256'], (string) $policy['policy_sha256'])
            || !hash_equals((string) $run['content_sha256'], $pinned->contentSha256())
            || !hash_equals((string) $run['difficulty_views_sha256'], $views->sha256())
        ) {
            throw DomainError::releaseUnavailable(
                "Run {$run['run_id']} historical artifact hashes do not match the retained release"
            );
        }

        $context = new self(
            $this->store,
            $this->regulatory,
            $policy,
            $content,
            $pinned->contentVersion(),
            $pinned->contentSha256(),
            $this->integrityControls,
            $views,
            $this->challenges,
            $this->projectRoot,
            $this->retention,
            $this->shareStore
        );
        $context->assertCompatibleRelease($run);
        $this->releaseContexts[$key] = $context;

        return $context;
    }

    private function decisionRequestHash(string $runId, string $stageId, string $choiceId): string
    {
        return CanonicalJson::sha256([
            'run_id' => $runId,
            'stage_id' => $stageId,
            'choice_id' => $choiceId,
        ]);
    }

    /**
     * @param array<string, mixed> $run
     */
    private function reconstructedScoreVerification(array $run): array
    {
        $selections = $this->store->boardSelections((string) $run['run_id']);
        $fields = $this->views->filterFields((string) $run['difficulty'], $this->policy['board_fields']);
        $boardResult = $this->builder->forBoard($selections, $fields);
        $result = $this->policy['calculator']->calculate(
            $this->reconstructFirings((string) $run['run_id'], $boardResult['firings']),
            (string) $run['risk_appetite']
        );

        return $this->scoreVerification($run, $result);
    }

    /**
     * @param array<string, mixed> $run
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function scoreVerification(array $run, array $result): array
    {
        $dimensions = DimensionComparison::compare(
            (array) ($run['dimensions'] ?? []),
            (array) ($result['dimensions'] ?? [])
        );
        $scoreMatches = is_int($run['score'] ?? null)
            && is_int($result['score'] ?? null)
            && $run['score'] === $result['score'];

        return [
            'verified' => $scoreMatches && $dimensions['matches'],
            'score_matches' => $scoreMatches,
            'dimensions_match' => $dimensions['matches'],
            'missing_from_stored' => $dimensions['missing_from_stored'],
            'missing_from_reconstructed' => $dimensions['missing_from_reconstructed'],
            'unexpected_in_stored' => $dimensions['unexpected_in_stored'],
            'unexpected_in_reconstructed' => $dimensions['unexpected_in_reconstructed'],
            'invalid_in_stored' => $dimensions['invalid_in_stored'],
            'invalid_in_reconstructed' => $dimensions['invalid_in_reconstructed'],
            'mismatched_dimensions' => $dimensions['mismatched_dimensions'],
        ];
    }

    private function pinBundles(): void
    {
        // One implementation for both this service and the reconciliation job in
        // bin/migrate.php, so the two can never write different canonical payloads
        // for the same hash.
        BundleSeeder::seed(
            $this->store,
            $this->policy,
            $this->regulatory,
            $this->contentVersion,
            $this->contentSha256
        );
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
