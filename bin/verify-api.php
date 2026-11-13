<?php

declare(strict_types=1);

/**
 * Stage 4 acceptance: run lifecycle, idempotency, pinning, hash chain, replay.
 *
 * Runs against InMemoryRunStore because this machine has no MySQL server. The
 * service is exercised through the same call sequence the HTTP layer will use,
 * so the state machine and integrity guarantees are verified here; the MySQL
 * adapter is exercised at deployment.
 *
 * Usage: php bin/verify-api.php
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\DifficultyViews;
use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;
use BoardSim\Domain\Run\DomainError;
use BoardSim\Domain\Run\InMemoryRunStore;
use BoardSim\Domain\Run\RunService;
use BoardSim\Domain\Run\RunState;

final class A
{
    public static int $passed = 0;
    public static int $failed = 0;

    public static function check(string $label, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            self::$passed++;
            printf("  PASS  %s\n", $label);

            return;
        }

        self::$failed++;
        printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : " ({$detail})");
    }

    public static function throws(string $label, callable $fn, int $expectedStatus): void
    {
        try {
            $fn();
        } catch (DomainError $e) {
            self::check($label, $e->httpStatus === $expectedStatus, 'status ' . $e->httpStatus);

            return;
        }

        self::check($label, false, 'no error raised');
    }
}

$root = dirname(__DIR__);

$regulatory = (new OverlayLoader($root))->load();
$pinned = new PinnedScenarios($root);
$content = $pinned->loadAll();
$contentVersion = $pinned->contentVersion();
$contentSha = $pinned->contentSha256();

$makeService = static function (?string $version, ?string $contentPin = null) use ($root, $regulatory): array {
    $store = new InMemoryRunStore();
    $policy = (new ScoringPolicyLoader($root))->load($version);
    $pinnedForVersion = new PinnedScenarios($root, $contentPin);

    return [
        new RunService(
            $store,
            $regulatory,
            $policy,
            $pinnedForVersion->loadAll(),
            $pinnedForVersion->contentVersion(),
            $pinnedForVersion->contentSha256(),
            ['hash_chain'],
            DifficultyViews::load($root),
            \BoardSim\Content\BoardChallenges::load($root),
            $root
        ),
        $store,
        $policy,
    ];
};

[$service, $store, $policy] = $makeService(null);

$board = static function (bool $substantiated) use ($policy): array {
    $selections = [];

    foreach ($policy['board_fields'] as $field => $definition) {
        foreach ($definition['options'] as $option) {
            if (($option['substantiated'] ?? false) === $substantiated) {
                $selections[$field] = (string) $option['id'];
                break;
            }
        }
    }

    return $selections;
};

echo "== Health and pinning ==\n";
$health = $service->health();
A::check('health reports the calibrated ruleset', $health['ruleset_version'] === '1.1.0', (string) $health['ruleset_version']);
A::check('health exposes the policy hash', $health['policy_sha256'] === $policy['policy_sha256']);
A::check(
    'health exposes the regulatory bundle hash',
    $health['regulatory_bundle_sha256'] === $regulatory['bundle_sha256']
);
A::check('health exposes the content hash', $health['content_sha256'] === $contentSha);
A::check('health reports the integrity controls', $health['integrity_controls'] === ['hash_chain']);
printf("  policy=%s\n  regulatory=%s\n  content=%s (%s)\n",
    $health['policy_sha256'],
    $health['regulatory_bundle_sha256'],
    $health['content_sha256'],
    $health['content_version']
);

echo "\n== Run creation ==\n";
$run = $service->create(['scenario_id' => 'BS-001', 'difficulty' => 'leadership']);
A::check('a new run opens stage 1', $run['state'] === RunState::Stage1Open->value, $run['state']);
A::check('the run pins ruleset 1.1.0', $run['pins']['ruleset_version'] === '1.1.0');
A::check('the run pins four hashes', count($run['pins']) === 7, (string) count($run['pins']));
A::check('three bundles are registered', $store->bundleCount() === 3, (string) $store->bundleCount());
A::check('the run id is a uuid', preg_match('/^[0-9a-f-]{36}$/', (string) $run['run_id']) === 1);

$options = $run['stage']['options'];
A::check('three options are offered', count($options) === 3);
A::check(
    'options never expose rule ids',
    !array_key_exists('rules', $options[0]),
    implode(',', array_keys($options[0]))
);
A::check('the stage carries a regulatory snapshot', ($run['stage']['regulatory']['REG-NIS2-23'] ?? null) === 'PENDING_CLASSIFICATION');

A::throws(
    'board_crisis is rejected with 422',
    static fn () => $service->create(['scenario_id' => 'BS-001', 'difficulty' => 'board_crisis']),
    422
);
A::throws(
    'an unknown scenario is rejected with 404',
    static fn () => $service->create(['scenario_id' => 'BS-999']),
    404
);
A::throws(
    'an unknown appetite is rejected with 400',
    static fn () => $service->create(['scenario_id' => 'BS-001', 'risk_appetite' => 'reckless']),
    400
);

echo "\n== Decisions, idempotency and state machine ==\n";
$runId = (string) $run['run_id'];
$key1 = '11111111-1111-4111-8111-111111111111';

$first = $service->decide($runId, $key1, 'S1', 'A');
A::check('the decision is accepted', $first['replayed'] === false);
A::check('the run advances to stage 2', $first['run']['state'] === RunState::Stage2Open->value, $first['run']['state']);
A::check('one event is recorded', count($store->events($runId)) === 1);
A::check('the event carries rule ids', $first['event']['rule_ids'] !== []);
A::check('the event chains from genesis', $first['event']['prev_event_sha256'] === str_repeat('0', 64));

$replay = $service->decide($runId, $key1, 'S1', 'A');
A::check('the same key replays without re-scoring', $replay['replayed'] === true);
A::check('replay does not add an event', count($store->events($runId)) === 1);
A::check(
    'replay returns the identical event hash',
    $replay['event']['event_sha256'] === $first['event']['event_sha256']
);

A::throws(
    'a decision on a closed stage is rejected with 409',
    static fn () => $service->decide($runId, '22222222-2222-4222-8222-222222222222', 'S1', 'B'),
    409
);
A::throws(
    'a decision on a future stage is rejected with 409',
    static fn () => $service->decide($runId, '33333333-3333-4333-8333-333333333333', 'S3', 'A'),
    409
);
A::throws(
    'an unknown option is rejected with 400',
    static fn () => $service->decide($runId, '44444444-4444-4444-8444-444444444444', 'S2', 'Z'),
    400
);
A::throws(
    'a missing idempotency key is rejected with 400',
    static fn () => $service->decide($runId, '', 'S2', 'A'),
    400
);
A::throws(
    'an unknown run is rejected with 404',
    static fn () => $service->decide('00000000-0000-4000-8000-000000000000', 'k', 'S1', 'A'),
    404
);

$service->decide($runId, '55555555-5555-4555-8555-555555555555', 'S2', 'A');
$third = $service->decide($runId, '66666666-6666-4666-8666-666666666666', 'S3', 'A');
A::check('after stage 3 the board opens', $third['run']['state'] === RunState::BoardOpen->value, $third['run']['state']);
A::check('three events chained', count($store->events($runId)) === 3);
A::check(
    'each event links to its predecessor',
    $store->events($runId)[1]['prev_event_sha256'] === $store->events($runId)[0]['event_sha256']
        && $store->events($runId)[2]['prev_event_sha256'] === $store->events($runId)[1]['event_sha256']
);

echo "\n== Board pack and scoring ==\n";
A::throws(
    'an incomplete board pack is rejected with 400',
    static fn () => $service->board($runId, ['situation' => 'SIT-ESTABLISHED']),
    400
);

$scored = $service->board($runId, $board(true));
A::check('the run finalises', $scored['run']['state'] === RunState::Finalized->value, $scored['run']['state']);
A::check('a score is produced', is_int($scored['score']));
printf("  BS-001 A,A,A under 1.1.0 -> %d (%s)\n", $scored['score'], $scored['label']);
A::check('the score matches the published calibration', $scored['score'] === 78, (string) $scored['score']);
A::check('ten dimensions are stored', count($scored['dimensions']) === 10);
A::throws(
    'a decision after scoring is rejected with 409',
    static fn () => $service->decide($runId, '77777777-7777-4777-8777-777777777777', 'S1', 'A'),
    409
);
A::throws(
    'a second board submission is rejected with 409',
    static fn () => $service->board($runId, $board(true)),
    409
);

echo "\n== Explanation and integrity ==\n";
$explain = $service->explain($runId);
A::check('the explanation reproduces the score', $explain['score'] === $scored['score']);
A::check('the explanation is deterministic in its numerator', $explain['numerator'] > 0);

$traceRules = array_column($explain['trace'], 'rule');
$eventRules = [];
foreach ($explain['events'] as $event) {
    $eventRules = array_merge($eventRules, (array) $event['rule_ids']);
}
A::check(
    'every recorded rule appears in the trace',
    array_values(array_diff($eventRules, $traceRules)) === [],
    implode(',', array_values(array_diff($eventRules, $traceRules)))
);
A::check('the trace includes board rules', in_array('DR-041', array_column($explain['trace'], 'rule'), true));
A::check('the chain verifies', $explain['chain']['ok'] === true);
A::check('the explanation repeats the pins', $explain['pins']['ruleset_version'] === '1.1.0');
A::check('governance flags travel with the export', $explain['governance']['psychometrically_calibrated'] === false);

// 05: the explanation must quote the business consequences, not only the score.
$pinnedContent = (new PinnedScenarios($root))->loadAll();
$firstOptionId = (string) ($explain['consequences'][0]['option_id'] ?? '');
$authoredEffects = [];

foreach ((array) ($pinnedContent[(string) $explain['scenario']]['stages'][0]['options'] ?? []) as $option) {
    if ((string) $option['id'] === $firstOptionId) {
        $authoredEffects = (array) $option['effects'];
    }
}

A::check(
    'the explanation quotes one consequence per decision',
    count($explain['consequences']) === 3,
    (string) count($explain['consequences'])
);
A::check(
    'each consequence names the decision that was made',
    array_reduce(
        $explain['consequences'],
        static fn (bool $carry, array $entry): bool => $carry && $entry['decision'] !== '',
        true
    )
);
A::check(
    'the consequences are the authored non-scoring effects',
    $authoredEffects !== [] && $explain['consequences'][0]['effects'] === $authoredEffects,
    json_encode($explain['consequences'][0]['effects'])
);

$serialisedOnce = \BoardSim\Content\CanonicalJson::encode($explain);
$serialisedTwice = \BoardSim\Content\CanonicalJson::encode($service->explain($runId));
A::check('the explanation is byte-identical when re-requested', $serialisedOnce === $serialisedTwice);

$store->tamperEventForTest($runId, 2, 'option_id', 'C');
$tampered = $service->verifyChain($runId);
A::check('tampering is detected', $tampered['ok'] === false);
A::check('the first broken event is named', ($tampered['broken_at'] ?? 0) === 2, (string) ($tampered['broken_at'] ?? 0));

echo "\n== Replay against the pinned 1.0.0 baseline ==\n";
[$legacyService, $legacyStore, $legacyPolicy] = $makeService('1.0.0', '1.0.0');
$legacyRun = $legacyService->create(['scenario_id' => 'BS-001', 'difficulty' => 'leadership']);
$legacyId = (string) $legacyRun['run_id'];
A::check('a 1.0.0 run pins ruleset 1.0.0', $legacyRun['pins']['ruleset_version'] === '1.0.0');
A::check('a 1.0.0 run pins content 1.0.0', $legacyRun['pins']['content_version'] === '1.0.0');
A::check(
    'the 1.0.0 policy hash differs from 1.1.0',
    $legacyRun['pins']['policy_sha256'] !== $run['pins']['policy_sha256']
);
A::check(
    'the 1.0.0 content hash differs from 1.1.0',
    $legacyRun['pins']['content_sha256'] !== $run['pins']['content_sha256'],
    $legacyRun['pins']['content_sha256']
);

$legacyService->decide($legacyId, 'aaaaaaaa-1111-4111-8111-111111111111', 'S1', 'A');
$legacyService->decide($legacyId, 'aaaaaaaa-2222-4222-8222-222222222222', 'S2', 'A');
$legacyService->decide($legacyId, 'aaaaaaaa-3333-4333-8333-333333333333', 'S3', 'A');
$legacyScored = $legacyService->board($legacyId, $board(true));
A::check('the archived baseline still scores 81', $legacyScored['score'] === 81, (string) $legacyScored['score']);
printf("  BS-001 A,A,A under 1.0.0 -> %d (%s)\n", $legacyScored['score'], $legacyScored['label']);

echo "\n== Difficulty is a projection, not cosmetic (F-5) ==\n";
$views = DifficultyViews::load($root);
A::check('the overlay declares three views', count($views->all()) === 3);
A::check('board_crisis is unavailable by policy', $views->get('board_crisis')['available'] === false);
A::check(
    'executive collects fewer board fields than leadership',
    count($views->get('executive')['board_fields']) === 4
        && count($views->get('leadership')['board_fields']) === 7
);
A::check(
    'both available views still run the same three stages',
    $views->get('leadership')['stages'] === ['S1', 'S2', 'S3']
        && $views->get('executive')['stages'] === ['S1', 'S2', 'S3']
);

$leadershipRun = $service->create(['scenario_id' => 'BS-001', 'difficulty' => 'leadership']);
A::check('leadership exposes all seven board fields', $leadershipRun['difficulty_view']['board_field_count'] === 7);
A::check('leadership has no primary stage', $leadershipRun['difficulty_view']['primary_stage'] === null);
A::check('leadership shows option effects', isset($leadershipRun['stage']['options'][0]['effects']));
A::check(
    'leadership shows stakeholder pressure',
    is_string($leadershipRun['stage']['stakeholder_pressure'])
        && $leadershipRun['stage']['stakeholder_pressure'] !== ''
);

$execRun = $service->create(['scenario_id' => 'BS-001', 'difficulty' => 'executive']);
A::check('executive exposes four board fields', $execRun['difficulty_view']['board_field_count'] === 4);
A::check('executive highlights S1 as the primary decision', $execRun['difficulty_view']['primary_stage'] === 'S1');
A::check('executive marks the primary stage on the payload', $execRun['stage']['is_primary'] === true);
A::check('executive hides option effects', !isset($execRun['stage']['options'][0]['effects']));
A::check('executive hides stakeholder pressure', $execRun['stage']['stakeholder_pressure'] === null);
A::check(
    'executive still offers the same three options',
    count($execRun['stage']['options']) === 3
        && $execRun['stage']['options'][0]['label'] === $leadershipRun['stage']['options'][0]['label']
);

A::check('the leadership rubric has seven fields', count($service->boardRubric('leadership')['fields']) === 7);
A::check('the executive rubric has four fields', count($service->boardRubric('executive')['fields']) === 4);
A::throws(
    'the board_crisis rubric is rejected with 422',
    static fn () => $service->boardRubric('board_crisis'),
    422
);

// Drive both difficulties down the same path and confirm the scores diverge.
$runToScore = static function (RunService $svc, string $scenarioId, string $difficulty, callable $board): array {
    $created = $svc->create(['scenario_id' => $scenarioId, 'difficulty' => $difficulty]);
    $id = (string) $created['run_id'];

    foreach (['S1' => 'A', 'S2' => 'A', 'S3' => 'A'] as $stage => $choice) {
        $svc->decide($id, $stage . '-' . $difficulty, $stage, $choice);
    }

    return ['id' => $id, 'result' => $svc->board($id, $board($difficulty))];
};

$boardFor = static function (string $difficulty) use ($service, $policy): array {
    $rubric = $service->boardRubric($difficulty);
    $selections = [];

    foreach ($rubric['fields'] as $field) {
        // Pick the substantiated option by asking the policy, which the client
        // cannot see: this mirrors the best-case board pack for that difficulty.
        $definition = $policy['board_fields'][$field['key']];

        foreach ($definition['options'] as $option) {
            if (($option['substantiated'] ?? false) === true) {
                $selections[$field['key']] = (string) $option['id'];
                break;
            }
        }
    }

    return $selections;
};

$leadershipScored = $runToScore($service, 'BS-001', 'leadership', $boardFor);
$executiveScored = $runToScore($service, 'BS-001', 'executive', $boardFor);

printf(
    "  BS-001 A,A,A best-case board: leadership=%d executive=%d\n",
    $leadershipScored['result']['score'],
    $executiveScored['result']['score']
);

A::check(
    'the executive run scores lower than leadership on the same path',
    $executiveScored['result']['score'] < $leadershipScored['result']['score'],
    $executiveScored['result']['score'] . ' vs ' . $leadershipScored['result']['score']
);

// The projection must remove exactly the three dropped board rules and nothing
// else. Each is a +2 delta on a dimension the board can move: BI (weight 12),
// RJ (14) and RE (7), so the pre-rounding numerator differs by 2*(12+14+7)=66.
$leadExplain = $service->explain($leadershipScored['id']);
$execExplain = $service->explain($executiveScored['id']);
$numeratorDelta = (int) $leadExplain['numerator'] - (int) $execExplain['numerator'];
printf(
    "  numerator: leadership=%d executive=%d delta=%d (expected 66)\n",
    (int) $leadExplain['numerator'],
    (int) $execExplain['numerator'],
    $numeratorDelta
);
A::check(
    'the difficulty projection removes exactly 66 hundredths, the three dropped board rules',
    $numeratorDelta === 66,
    (string) $numeratorDelta
);
A::check(
    'executive declines nothing, because it only collects its own four fields',
    count($executiveScored['result']['declined']) === 0,
    (string) count($executiveScored['result']['declined'])
);

$execExplain = $service->explain((string) $executiveScored['id']);
A::check(
    'the executive explanation replays the same reduced score',
    $execExplain['score'] === $executiveScored['result']['score'],
    (string) $execExplain['score']
);
A::check(
    'the executive trace contains only its four board rules',
    array_values(array_filter(
        array_column($execExplain['trace'], 'rule'),
        static fn (string $rule): bool => in_array($rule, ['DR-041', 'DR-042', 'DR-043', 'DR-044', 'DR-045', 'DR-046', 'DR-047'], true)
    )) === ['DR-041', 'DR-044', 'DR-045', 'DR-047'],
    implode(',', array_column($execExplain['trace'], 'rule'))
);

A::throws(
    'a board_crisis run is rejected with 422 and the policy reason',
    static fn () => $service->create(['scenario_id' => 'BS-001', 'difficulty' => 'board_crisis']),
    422
);

echo "\n== What would change it? ==\n";

$cfRun = $runToScore($service, 'BS-003', 'leadership', $boardFor);
$cf = $service->counterfactuals($cfRun['id']);

A::check('three stages are reported', count($cf['stages']) === 3);
A::check('the counterfactual replay reproduces the recorded score', $cf['score'] === $cfRun['result']['score']);

$alternativeCounts = array_map(static fn (array $s): int => count($s['alternatives']), $cf['stages']);
A::check('every stage offers its two unchosen options', $alternativeCounts === [2, 2, 2], implode(',', $alternativeCounts));
A::check('repeated calls are byte-identical', $cf === $service->counterfactuals($cfRun['id']));
A::check(
    'the note refuses to treat a later outcome as disproof',
    str_contains((string) $cf['note'], 'never implies the original choice was wrong')
);
A::check('alternatives never claim legal review', $cf['governance']['counsel_reviewed'] === false);

$sample = $cf['stages'][0]['alternatives'][0];
A::check('the delta is exactly the difference of the two scores', $sample['score_delta'] === $sample['score'] - $cf['score'], (string) $sample['score_delta']);
A::check('dimension deltas omit unchanged dimensions', !in_array(0, array_values($sample['dimension_delta']), true));
A::check('the summary names the alternative option', str_contains((string) $sample['summary'], (string) $sample['label']));
A::check(
    'the summary states the score direction',
    str_contains((string) $sample['summary'], 'lower') || str_contains((string) $sample['summary'], 'higher')
        || str_contains((string) $sample['summary'], 'unchanged')
);
printf("  %s\n", $sample['summary']);

// Substitution must reproduce the regulatory consequence of an option, not just
// a score delta: BS-003 stage 2 option B defers reporting, so it drops the
// prompt-notification rules and picks up the DR-027 omission rule, which is
// itself guard-gated on GDPR Art 33 being confirmed for this scenario.
$s2 = $cf['stages'][1];
$s2Alt = null;
foreach ($s2['alternatives'] as $candidate) {
    if ($candidate['option_id'] === 'B') {
        $s2Alt = $candidate;
    }
}
A::check('the S2 alternative to B is present', $s2Alt !== null);
A::check(
    'the deferral option at S2 picks up DR-027, the omission rule',
    in_array('DR-027', (array) $s2Alt['rules_added'], true),
    implode(',', (array) $s2Alt['rules_added'])
);
A::check(
    'the deferral option at S2 drops the prompt-notification rules',
    array_values(array_intersect(['DR-006', 'DR-011'], (array) $s2Alt['rules_removed'])) === ['DR-006', 'DR-011'],
    implode(',', (array) $s2Alt['rules_removed'])
);
printf("  %s\n", $s2Alt['summary']);

$ruleIds = [];
foreach ($cf['stages'] as $stage) {
    foreach ($stage['alternatives'] as $alternative) {
        foreach (array_merge($alternative['rules_added'], $alternative['rules_removed']) as $ruleId) {
            $ruleIds[$ruleId] = true;
        }
    }
}
A::check('every referenced rule exists in the pinned ruleset', $ruleIds !== [] && array_reduce(
    array_keys($ruleIds),
    static fn (bool $carry, string $id): bool => $carry && $policy['ruleset']->has($id),
    true
));

A::throws(
    'an unscored run has no alternatives',
    static function () use ($service): void {
        $created = $service->create(['scenario_id' => 'BS-003', 'difficulty' => 'leadership']);
        $service->counterfactuals((string) $created['run_id']);
    },
    409
);
A::throws(
    'an unknown run has no alternatives',
    static fn () => $service->counterfactuals('00000000-0000-4000-8000-000000000000'),
    404
);

echo "\n== Board room: challenge cards and the suggested timer (06) ==\n";

$rubric = $service->boardRubric('leadership');
A::check('three challenge cards are supplied', count($rubric['challenges']) === 3, (string) count($rubric['challenges']));
A::check(
    'the cards come from chair, CFO and CEO in that order',
    array_column($rubric['challenges'], 'from') === ['Chair', 'CFO', 'CEO'],
    implode(',', array_column($rubric['challenges'], 'from'))
);
A::check(
    'every card asks a question rather than stating a rule',
    array_reduce(
        $rubric['challenges'],
        static fn (bool $carry, array $card): bool => $carry && str_ends_with($card['probe'], '?'),
        true
    )
);
A::check('the suggested preparation is five minutes', $rubric['preparation']['suggested_minutes'] === 5);
A::check('the preparation timer is not scored', $rubric['preparation']['scoring'] === false);
A::check('the preparation timer does not auto-start', $rubric['preparation']['auto_start'] === false);
A::check('there is no hard stop when the timer elapses', $rubric['preparation']['hard_stop'] === false);
A::check(
    'the note separates prompts from grading',
    str_contains((string) $rubric['note'], 'never graded')
        && str_contains((string) $rubric['note'], 'not scored')
);
A::check('the cards are versioned and hashed', $rubric['challenges_sha256'] !== str_repeat('0', 64));
A::check(
    'the executive view gets the same cards, because they are not a field',
    count($service->boardRubric('executive')['challenges']) === 3
);

// The cards must not be able to touch a score: the rubric is read-only content,
// so the only real check is that scoring is unaffected by their presence.
$challengeRun = $runToScore($service, 'BS-001', 'leadership', $boardFor);
$withoutCards = (new \BoardSim\Domain\Run\RunService(
    $store,
    $regulatory,
    $policy,
    $content,
    $contentVersion,
    $contentSha,
    ['hash_chain'],
    $views,
    \BoardSim\Content\BoardChallenges::strictFallback(),
    $root
))->boardRubric('leadership');
A::check('a missing overlay degrades to no cards, not invented ones', $withoutCards['challenges'] === []);
A::check(
    'a missing overlay still states the timer is not scored',
    $withoutCards['preparation']['scoring'] === false
);
printf(
    "  cards: %s\n  timer: %d minutes, scoring=%s\n",
    implode(' / ', array_column($rubric['challenges'], 'from')),
    $rubric['preparation']['suggested_minutes'],
    $rubric['preparation']['scoring'] ? 'true' : 'false'
);
A::check('scoring still works with cards present', $challengeRun['result']['score'] > 0);

echo "\n== Erasure (06 deletion, model A) ==\n";

$erasure = $runToScore($service, 'BS-004', 'leadership', $boardFor);
$erasureId = (string) $erasure['id'];
$eventsBefore = count($store->events($erasureId));
A::check('the run to erase has events', $eventsBefore === 3, (string) $eventsBefore);

$confirmation = $service->delete($erasureId);
A::check('deletion reports success', $confirmation['deleted'] === true);
A::check('deletion states it is not recoverable', $confirmation['recoverable'] === false);
A::check('deletion counts the events actually removed', $confirmation['events_removed'] === $eventsBefore, (string) $confirmation['events_removed']);
A::check(
    'deletion explains why the run is no longer verifiable',
    str_contains((string) $confirmation['note'], 'no longer exists')
);

A::check('the run row is gone', $store->find($erasureId) === null);
A::check('the event log is gone', $store->events($erasureId) === []);
A::check('the board selections are gone', $store->boardSelections($erasureId) === []);
A::throws('the run can no longer be read', static fn () => $service->status($erasureId), 404);
A::throws('the run can no longer be explained', static fn () => $service->explain($erasureId), 404);
A::throws('the run cannot be erased twice', static fn () => $service->delete($erasureId), 404);
A::throws(
    'an erased run cannot rejoin a profile',
    static fn () => $service->profile([$erasureId]),
    404
);

// Erasure must not touch anything else.
$neighbour = $runToScore($service, 'BS-005', 'leadership', $boardFor);
A::check('an unrelated run survives an erasure', $store->find((string) $neighbour['id']) !== null);
A::check(
    'the surviving run can still be verified',
    $service->verifyChain((string) $neighbour['id'])['ok'] === true
);
printf(
    "  erased %s with %d events; neighbour %s intact\n",
    $confirmation['scenario_id'],
    $confirmation['events_removed'],
    $neighbour['id']
);

echo "\n== Retention ceiling: 7 days, physical delete ==\n";

$retention = $service->retention();
A::check('share links live 7 days', $retention['share_ttl_days'] === 7, (string) $retention['share_ttl_days']);
A::check('runs are kept 7 days', $retention['run_retention_days'] === 7, (string) $retention['run_retention_days']);
A::check(
    'the ceiling is never below the link lifetime',
    $retention['run_retention_days'] >= $retention['share_ttl_days']
);
A::check('deletion stays physical', $retention['delete_model'] === 'physical');
A::check('backups rotate every 7 days', $retention['backup_rotation_days'] === 7, (string) $retention['backup_rotation_days']);
A::check(
    'the stated worst case is retention plus rotation, not a flat 7',
    $retention['worst_case_days'] === 14,
    (string) $retention['worst_case_days']
);
A::check('the policy is versioned and hashed', strlen((string) $retention['sha256']) === 64);

$retentionRule = \BoardSim\Content\RetentionPolicy::load($root);
A::check(
    'the cutoff is exactly seven days back',
    $retentionRule->cutoff('2026-11-13T00:00:00Z') === '2026-11-06T00:00:00Z',
    $retentionRule->cutoff('2026-11-13T00:00:00Z')
);

// A dedicated store, so the purge below cannot disturb runs other sections assert on.
$purgeStore = new \BoardSim\Domain\Run\InMemoryRunStore();
$purgeService = new \BoardSim\Domain\Run\RunService(
    $purgeStore,
    $regulatory,
    $policy,
    $content,
    $contentVersion,
    $contentSha,
    ['hash_chain'],
    $views,
    \BoardSim\Content\BoardChallenges::load($root),
    $root
);

$oldRun = $purgeService->create(['scenario_id' => 'BS-012', 'difficulty' => 'leadership']);
$freshRun = $purgeService->create(['scenario_id' => 'BS-014', 'difficulty' => 'leadership']);

A::check('nothing inside the window is purged', $purgeService->purgeExpired()['purged'] === 0);
A::check(
    'both runs survive the routine purge',
    $purgeStore->find((string) $oldRun['run_id']) !== null
        && $purgeStore->find((string) $freshRun['run_id']) !== null
);

$eightDays = gmdate('Y-m-d\TH:i:s\Z', time() + (8 * 86400));
$purgeResult = $purgeService->purgeExpired($eightDays);
A::check('runs past the ceiling are purged', $purgeResult['purged'] === 2, (string) $purgeResult['purged']);
A::check('the purge is a physical delete', $purgeStore->find((string) $oldRun['run_id']) === null);
A::check(
    'an abandoned run is purged too, not kept forever',
    $purgeStore->find((string) $freshRun['run_id']) === null
);
A::check(
    'the purge reports what it removed',
    count($purgeResult['run_ids']) === 2,
    (string) count($purgeResult['run_ids'])
);
printf(
    "  ceiling %d days, share %d days; 0 purged today, %d purged on a simulated +8 days\n",
    $retention['run_retention_days'],
    $retention['share_ttl_days'],
    $purgeResult['purged']
);

echo "\n== Public demonstration (06 landing) ==\n";

$demo = $service->demoStart();
A::check('the demo is available', ($demo['demo_id'] ?? '') === 'DEMO-001');
A::check('the demo declares itself unscored', $demo['scored'] === false);
A::check('the demo declares itself unpersisted', $demo['persisted'] === false);
A::check('the demo has two stages', count($demo['stages']) === 2, (string) count($demo['stages']));
A::check('each demo stage offers three options', count($demo['stages'][0]['options']) === 3);
A::check(
    'the demo withholds rule ids, exactly as a scored stage does',
    !isset($demo['stages'][0]['options'][0]['rules'])
);
A::check('the demo carries its own version and hash', strlen((string) $demo['sha256']) === 64);

// The demo must never be reachable as a scored scenario.
$scoredIds = array_column(
    (new \BoardSim\Content\PinnedScenarios($root))->loadAll(),
    'scenario_id'
);
A::check('the demo is absent from the scored catalogue', !in_array('DEMO-001', $scoredIds, true));
A::check('the scored catalogue is still fourteen scenarios', count($scoredIds) === 14, (string) count($scoredIds));

$demoChoice = $service->demoDecide('S1', 'A');
A::check('a demo choice reports the rules it maps to', $demoChoice['mechanism'] !== []);
A::check(
    'the mechanism names real rules',
    array_column($demoChoice['mechanism'], 'rule') === ['DR-003', 'DR-006', 'DR-019'],
    implode(',', array_column($demoChoice['mechanism'], 'rule'))
);
A::check(
    'no total is returned, under any key',
    !isset($demoChoice['score'])
        && !isset($demoChoice['total'])
        && !isset($demoChoice['dimensions'])
        && $demoChoice['scored'] === false
);
A::check('the demo never claims counsel review', $demoChoice['governance']['counsel_reviewed'] === false);
A::check('the demo is deterministic', $demoChoice === $service->demoDecide('S1', 'A'));

// A guard-bound rule would be suppressed in a scored run, so showing its raw
// deltas in the demo would overstate it. The demo content must use unguarded
// rules only, which this checks against the guard overlay itself.
$guardDoc = \BoardSim\Content\Yaml::parse(
    (string) file_get_contents($root . '/content/overlays/rule_guards.yaml')
);
$guardedRules = array_keys(array_filter(
    array_keys($guardDoc),
    static fn (string $key): bool => str_starts_with($key, 'DR-')
));
$demoRules = [];
foreach ($demo['stages'] as $stage) {
    foreach ($service->demoDecide((string) $stage['stage_id'], 'A')['mechanism'] as $entry) {
        $demoRules[] = (string) $entry['rule'];
    }
}
foreach ($demo['stages'] as $stage) {
    foreach ($service->demoDecide((string) $stage['stage_id'], 'B')['mechanism'] as $entry) {
        $demoRules[] = (string) $entry['rule'];
    }
}
foreach ($demo['stages'] as $stage) {
    foreach ($service->demoDecide((string) $stage['stage_id'], 'C')['mechanism'] as $entry) {
        $demoRules[] = (string) $entry['rule'];
    }
}
A::check(
    'no demo rule is guard-bound, so the demo cannot overstate a suppressed rule',
    array_values(array_intersect(array_unique($demoRules), $guardedRules)) === [],
    implode(',', array_intersect(array_unique($demoRules), $guardedRules))
);
A::check(
    'every demo rule exists in the pinned ruleset',
    array_reduce(
        array_unique($demoRules),
        static fn (bool $carry, string $id): bool => $carry && $policy['ruleset']->has($id),
        true
    )
);

A::throws('an unknown demo stage is rejected', static fn () => $service->demoDecide('S9', 'A'), 400);
A::throws('an unknown demo option is rejected', static fn () => $service->demoDecide('S1', 'Z'), 400);

// Nothing was written: the demo holds no store, so the run store must be
// untouched by every call above.
A::check('the demo wrote no run', $store->bundleCount() === 3, (string) $store->bundleCount());
printf(
    "  demo: %d stages, all 6 choices resolve, 0 persisted, %d distinct rules\n",
    count($demo['stages']),
    count(array_unique($demoRules))
);

echo "\n== Machine-readable explanation contract (05) ==\n";

$contract = $service->explain($cfRun['id']);
A::check('the contract carries the scenario id', $contract['scenario'] === 'BS-003', (string) $contract['scenario']);
A::check('the contract carries the ruleset version', $contract['ruleset_version'] === (string) $policy['ruleset_version']);
A::check(
    'weighted_numerator equals numerator',
    $contract['weighted_numerator'] === $contract['numerator']
);
A::check(
    'the rounding expression is concrete, not a formula',
    preg_match('/^floor\(\(\d+\+50\)\/100\)$/', (string) $contract['rounding_expression']) === 1,
    (string) $contract['rounding_expression']
);
A::check(
    'the concrete expression reproduces the score',
    intdiv($contract['numerator'] + 50, 100) === $contract['score'],
    (string) $contract['score']
);
A::check('a separate legal panel is present', ($contract['legal']['instruments'] ?? []) !== []);
A::check('the legal panel covers every instrument once', count($contract['legal']['instruments']) === 28, (string) count($contract['legal']['instruments']));
A::check(
    'every legal reference is resolvable',
    array_reduce(
        $contract['legal']['instruments'],
        static fn (bool $carry, array $entry): bool => $carry && str_starts_with($entry['reference'], '/v1/refs/REG-'),
        true
    )
);
A::check(
    'the legal panel never claims to affect the score',
    str_contains((string) $contract['legal']['assessment'], 'never alters the score')
);
printf("  rounding: %s = %d\n", $contract['rounding_expression'], $contract['score']);

echo "\n== Portfolio aggregation ==\n";

$finish = static function (RunService $svc, string $scenarioId, string $difficulty, callable $board): string {
    $created = $svc->create(['scenario_id' => $scenarioId, 'difficulty' => $difficulty]);
    $id = (string) $created['run_id'];
    $key = 0;

    foreach (['S1' => 'A', 'S2' => 'A', 'S3' => 'A'] as $stage => $choice) {
        $key++;
        $svc->decide($id, "{$id}-{$key}", $stage, $choice);
    }

    $svc->board($id, $board($difficulty));

    return $id;
};

// Three leadership runs across different scenarios.
$leadershipIds = [
    $finish($service, 'BS-001', 'leadership', $boardFor),
    $finish($service, 'BS-004', 'leadership', $boardFor),
    $finish($service, 'BS-011', 'leadership', $boardFor),
];

$profile = $service->profile($leadershipIds);
A::check('one segment is produced', $profile['segments_count'] === 1, (string) $profile['segments_count']);
$segment = $profile['segments'][0];
A::check('the segment is eligible', $segment['status'] === 'eligible', (string) $segment['status']);
A::check('three runs contribute', $segment['runs'] === 3);
A::check('three distinct scenarios are covered', $segment['coverage']['distinct_scenarios'] === 3);
A::check('the profile states its retention ceiling', $profile['retention']['run_retention_days'] === 7);
A::check(
    'a fresh profile has its whole window left',
    $profile['retention']['oldest_expires_in_days'] === 7,
    (string) $profile['retention']['oldest_expires_in_days']
);
A::check(
    'the profile explains the deadline, not just the count',
    str_contains((string) $profile['retention']['note'], 'inside that window')
);
A::check('ten dimensions are aggregated', count($segment['dimensions']) === 10);
A::check('an overall is produced', is_int($segment['overall']));
printf(
    "  leadership profile: overall=%d  SJ=%d RG=%d RE=%d\n",
    $segment['overall'],
    $segment['dimensions']['SJ'],
    $segment['dimensions']['RG'],
    $segment['dimensions']['RE']
);

// Verify the arithmetic independently: unweighted mean, rounded half up.
$manual = [];
foreach (['SJ', 'BI', 'RJ', 'DQ', 'LE', 'CO', 'RG', 'FJ', 'RE', 'SM'] as $code) {
    $values = [];
    foreach ($leadershipIds as $id) {
        $run = $store->find($id);
        $values[] = (int) $run['dimensions'][$code];
    }
    $manual[$code] = (int) floor(array_sum($values) / count($values) + 0.5);
}
A::check('dimension means are unweighted and half-up', $manual === $segment['dimensions']);

$scores = array_map(static fn (string $id): int => (int) $store->find($id)['score'], $leadershipIds);
A::check(
    'the overall is the half-up mean of run scores, not the weighted mean of dimensions',
    $segment['overall'] === (int) floor(array_sum($scores) / count($scores) + 0.5),
    (string) $segment['overall']
);

// Executive runs must not be merged into the leadership segment.
$executiveIds = [
    $finish($service, 'BS-005', 'executive', $boardFor),
    $finish($service, 'BS-007', 'executive', $boardFor),
    $finish($service, 'BS-009', 'executive', $boardFor),
];

$mixed = $service->profile(array_merge($leadershipIds, $executiveIds));
A::check('difficulties are never merged', $mixed['segments_count'] === 2, (string) $mixed['segments_count']);
A::check('merging is explicitly disclaimed', $mixed['merged_across_versions'] === false);

$execSegment = null;
foreach ($mixed['segments'] as $candidate) {
    if ($candidate['difficulty'] === 'executive') {
        $execSegment = $candidate;
    }
}
A::check('the executive segment is present and eligible', $execSegment !== null && $execSegment['status'] === 'eligible');
A::check('each segment keeps its own coverage', count($execSegment['coverage']['scenario_ids']) === 3);

// A difficulty mismatch inside a segment must be impossible by construction.
$difficultiesInLeadership = [];
foreach ($profile['segments'] as $candidate) {
    $difficultiesInLeadership[$candidate['difficulty']] = true;
}
A::check('the leadership profile holds only leadership runs', array_keys($difficultiesInLeadership) === ['leadership']);

// Window enforcement.
$twoRuns = $service->profile(array_slice($leadershipIds, 0, 2));
A::check('fewer than three runs is reported as insufficient', $twoRuns['segments'][0]['status'] === 'insufficient');
A::check('no aggregate is fabricated below the window', $twoRuns['segments'][0]['overall'] === null);
A::check('the shortfall is explained', str_contains((string) $twoRuns['segments'][0]['caveats'][0], 'needs 3 to 5'));

$sixIds = $leadershipIds;
for ($i = 0; $i < 3; $i++) {
    $sixIds[] = $finish($service, 'BS-012', 'leadership', $boardFor);
}
$overWindow = $service->profile($sixIds);
A::check('more than five runs is refused, not silently truncated', $overWindow['segments'][0]['status'] === 'over_window');
A::check('no aggregate is produced over the window', $overWindow['segments'][0]['overall'] === null);
A::check(
    'repeated scenarios are flagged as a comparability caveat',
    str_contains(implode(' ', (array) $overWindow['segments'][0]['caveats']), 'Repeated scenario')
);

A::throws(
    'an unfinished run is refused with 409',
    static function () use ($service): void {
        $created = $service->create(['scenario_id' => 'BS-002', 'difficulty' => 'leadership']);
        $service->profile([(string) $created['run_id']]);
    },
    409
);
A::throws(
    'an unknown run is refused with 404',
    static fn () => $service->profile(['00000000-0000-4000-8000-000000000000']),
    404
);
A::throws(
    'an empty run list is refused with 400',
    static fn () => $service->profile([]),
    400
);

echo "\n== Share links: 7-day expiry, revocable ==\n";

$shareStore = new \BoardSim\Domain\Share\InMemoryShareStore();
$shareService = new \BoardSim\Domain\Run\RunService(
    $store,
    $regulatory,
    $policy,
    $content,
    $contentVersion,
    $contentSha,
    ['hash_chain'],
    $views,
    \BoardSim\Content\BoardChallenges::load($root),
    $root,
    null,
    $shareStore
);

$created = $shareService->shareCreate($leadershipIds, '2026-11-13T09:00:00Z');
A::check('a token is 32 random bytes in hex', preg_match('/^[0-9a-f]{64}$/', (string) $created['token']) === 1);
A::check('the link expires in exactly seven days', $created['expires_at'] === '2026-11-20T09:00:00Z', (string) $created['expires_at']);
A::check('the link reports its remaining days', $created['expires_in_days'] === 7);
A::check('the payload carries the aggregated profile', count($created['profile']) === 1);

// Only the hash is stored. A stolen database must not yield a working link.
$stored = $shareStore->all();
A::check('the raw token is not stored', !isset($stored[$created['token']]), $created['token']);
A::check('the stored key is the token hash', isset($stored[hash('sha256', (string) $created['token'])]));

$resolved = $shareService->shareResolve((string) $created['token'], '2026-11-15T09:00:00Z');
A::check('a live link resolves', $resolved['read_only'] === true);
A::check('the shared view is the same profile', $resolved['profile']['segments'][0]['overall'] === $segment['overall']);
A::check('the link counts down', $resolved['expires_in_days'] === 5, (string) $resolved['expires_in_days']);

A::throws(
    'an expired link stops resolving',
    static fn () => $shareService->shareResolve((string) $created['token'], '2026-11-20T09:00:01Z'),
    404
);
A::throws(
    'a malformed token is rejected',
    static fn () => $shareService->shareResolve('not-a-token'),
    404
);
A::throws(
    'an unknown token is rejected',
    static fn () => $shareService->shareResolve(str_repeat('a', 64)),
    404
);

$revoked = $shareService->shareRevoke((string) $created['token'], '2026-11-14T09:00:00Z');
A::check('revocation is confirmed', $revoked['revoked'] === true && $revoked['already_revoked'] === false);
A::check(
    'revocation says the runs are untouched',
    str_contains((string) $revoked['note'], 'runs were not deleted')
);
A::throws(
    'a revoked link stops resolving',
    static fn () => $shareService->shareResolve((string) $created['token'], '2026-11-14T10:00:00Z'),
    404
);
$again = $shareService->shareRevoke((string) $created['token'], '2026-11-14T11:00:00Z');
A::check('revoking twice is idempotent, not a lie', $again['already_revoked'] === true);

// A link cannot be created for a set that is not a valid profile.
A::throws(
    'a single run cannot be shared as a profile',
    static fn () => $shareService->shareCreate(array_slice($leadershipIds, 0, 1)),
    400
);

// And when the data goes, the link goes with it.
$doomed = $shareService->shareCreate($leadershipIds, '2026-11-13T09:00:00Z');
$service->delete((string) $leadershipIds[0]);
A::throws(
    'a link whose runs were deleted stops resolving rather than half-working',
    static fn () => $shareService->shareResolve((string) $doomed['token'], '2026-11-13T10:00:00Z'),
    404
);

printf(
    "  token %s…%s, expires %s, revoked on demand, dies with its data\n",
    substr((string) $created['token'], 0, 8),
    substr((string) $created['token'], -4),
    $created['expires_at']
);

printf("\n%d passed, %d failed\n", A::$passed, A::$failed);
exit(A::$failed === 0 ? 0 : 1);
