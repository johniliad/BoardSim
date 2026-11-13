<?php

declare(strict_types=1);

/**
 * HTTP contract test for the router, exercised without a web server.
 *
 * This is the contract the SPA is written against, so it is worth asserting
 * directly rather than only through curl. Usage: php bin/verify-http.php
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\DifficultyViews;
use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;
use BoardSim\Domain\Run\InMemoryRunStore;
use BoardSim\Domain\Run\RunService;
use BoardSim\Http\ApiRouter;

$passed = 0;
$failed = 0;

/**
 * @param array{0: int, 1: mixed} $response
 */
function expect(string $label, array $response, int $status): mixed
{
    global $passed, $failed;

    if ($response[0] !== $status) {
        $failed++;
        printf("  FAIL  %s (status %d, expected %d)\n", $label, $response[0], $status);

        return $response[1];
    }

    $passed++;
    printf("  PASS  %s\n", $label);

    return $response[1];
}

function assertThat(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        printf("  PASS  %s\n", $label);

        return;
    }

    $failed++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : " ({$detail})");
}

$root = dirname(__DIR__);
$pinned = new PinnedScenarios($root);

$service = new RunService(
    new InMemoryRunStore(),
    (new OverlayLoader($root))->load(),
    (new ScoringPolicyLoader($root))->load(),
    $pinned->loadAll(),
    $pinned->contentVersion(),
    $pinned->contentSha256(),
    ['hash_chain', 'dev_in_memory_store'],
    DifficultyViews::load($root),
    \BoardSim\Content\BoardChallenges::load($root),
    $root
);

$router = new ApiRouter($service, $pinned);

echo "== Read endpoints ==\n";
$health = expect('GET /v1/health', $router->handle('GET', '/v1/health', []), 200);
assertThat('health carries the three pins', isset(
    $health['policy_sha256'],
    $health['regulatory_bundle_sha256'],
    $health['content_sha256']
));

$catalogue = expect('GET /v1/scenarios', $router->handle('GET', '/v1/scenarios', []), 200);
assertThat('fourteen scenarios are listed', count($catalogue['scenarios']) === 14, (string) count($catalogue['scenarios']));
assertThat('board_crisis is advertised as unavailable', $catalogue['scenarios'][0]['board_crisis_available'] === false);

$ref = expect('GET /v1/refs/REG-GDPR-33', $router->handle('GET', '/v1/refs/REG-GDPR-33', []), 200);
assertThat('the reference carries an official URL', str_starts_with((string) $ref['source_url'], 'https://eur-lex.europa.eu'));
expect('GET /v1/refs/REG-NOPE', $router->handle('GET', '/v1/refs/REG-NOPE', []), 404);
expect('GET /v1/methodology', $router->handle('GET', '/v1/methodology', []), 200);

$rubric = expect('GET /v1/board-rubric', $router->handle('GET', '/v1/board-rubric', []), 200);
assertThat('the leadership rubric has seven fields', count($rubric['fields']) === 7, (string) count($rubric['fields']));
assertThat(
    'the rubric never leaks which option is substantiated',
    !isset($rubric['fields'][0]['options'][0]['substantiated'])
        && !isset($rubric['fields'][0]['options'][0]['reason'])
);

$execRubric = expect('GET /v1/board-rubric?difficulty=executive', $router->handle(
    'GET',
    '/v1/board-rubric?difficulty=executive',
    []
), 200);
assertThat('the executive rubric has four fields', count($execRubric['fields']) === 4, (string) count($execRubric['fields']));
expect('GET /v1/board-rubric?difficulty=board_crisis', $router->handle(
    'GET',
    '/v1/board-rubric?difficulty=board_crisis',
    []
), 422);

$difficulties = expect('GET /v1/difficulties', $router->handle('GET', '/v1/difficulties', []), 200);
assertThat('three difficulties are advertised', count($difficulties['difficulties']) === 3);
assertThat(
    'board_crisis is advertised unavailable with a reason',
    $difficulties['difficulties'][2]['available'] === false
        && is_string($difficulties['difficulties'][2]['unavailable_reason'])
);
expect('unknown route', $router->handle('GET', '/v1/nope', []), 404);

echo "\n== Run lifecycle over HTTP ==\n";
$run = expect(
    'POST /v1/runs',
    $router->handle('POST', '/v1/runs', ['scenario_id' => 'BS-003', 'difficulty' => 'leadership']),
    201
);
$runId = (string) $run['run_id'];
assertThat('the stage payload has no rule ids', !isset($run['stage']['options'][0]['rules']));
assertThat('the stage payload carries a regulatory snapshot', isset($run['stage']['regulatory']['REG-GDPR-33']));
assertThat(
    'BS-003 stage 1 reports GDPR-33 as pending',
    $run['stage']['regulatory']['REG-GDPR-33'] === 'PENDING_CLASSIFICATION',
    (string) $run['stage']['regulatory']['REG-GDPR-33']
);

expect('POST board_crisis', $router->handle('POST', '/v1/runs', [
    'scenario_id' => 'BS-003',
    'difficulty' => 'board_crisis',
]), 422);

expect('GET /v1/runs/{id}', $router->handle('GET', '/v1/runs/' . $runId, []), 200);

foreach ([['S1', 'B'], ['S2', 'B'], ['S3', 'A']] as $index => [$stage, $choice]) {
    $response = expect(
        "POST decisions {$stage}={$choice}",
        $router->handle('POST', "/v1/runs/{$runId}/decisions", [
            'idempotency_key' => sprintf('key-%d-%s', $index, bin2hex(random_bytes(4))),
            'stage_id' => $stage,
            'choice_id' => $choice,
        ]),
        200
    );
}

expect('POST decisions with a reused key is idempotent', $router->handle(
    'POST',
    "/v1/runs/{$runId}/decisions",
    ['idempotency_key' => 'replay-key', 'stage_id' => 'S1', 'choice_id' => 'A']
), 409); // stage 1 is closed now, so the state check wins over the key

$board = [
    'selections' => [
        'situation' => 'SIT-ESTABLISHED',
        'impact' => 'IMP-QUANTIFIED',
        'uncertainty' => 'UNC-NAMED',
        'action' => 'ACT-WITH-ALTERNATIVE',
        'funding' => 'FUN-BOUNDED',
        'residual_risk' => 'RES-OWNED',
        'ask' => 'ASK-RESOLUTION',
    ],
];

$scored = expect('POST /v1/runs/{id}/board', $router->handle('POST', "/v1/runs/{$runId}/board", $board), 200);
assertThat('a score is returned', is_int($scored['score']), (string) ($scored['score'] ?? 'none'));
assertThat('the score sits inside the ladder', $scored['score'] >= 0 && $scored['score'] <= 100);
printf("  BS-003 B,B,A -> %d (%s)\n", $scored['score'], $scored['label']);

$explain = expect('GET /v1/runs/{id}/explain', $router->handle('GET', "/v1/runs/{$runId}/explain", []), 200);
assertThat('the explanation verifies its chain', $explain['chain']['ok'] === true);
assertThat('the explanation carries the governance block', $explain['governance']['psychometrically_calibrated'] === false);
assertThat(
    'DR-027 fires because S2 option B defers reporting',
    in_array('DR-027', array_column($explain['trace'], 'rule'), true),
    implode(',', array_unique(array_column($explain['trace'], 'rule')))
);

printf("\n== Portfolio aggregation over HTTP ==\n");

$completed = [$runId];
for ($i = 0; $i < 2; $i++) {
    $extra = expect(
        'POST /v1/runs for the profile',
        $router->handle('POST', '/v1/runs', ['scenario_id' => $i === 0 ? 'BS-004' : 'BS-011', 'difficulty' => 'leadership']),
        201
    );
    $extraId = (string) $extra['run_id'];

    foreach (['S1' => 'C', 'S2' => 'C', 'S3' => 'C'] as $stage => $choice) {
        $router->handle('POST', "/v1/runs/{$extraId}/decisions", [
            'idempotency_key' => $extraId . '-' . $stage,
            'stage_id' => $stage,
            'choice_id' => $choice,
        ]);
    }

    $router->handle('POST', "/v1/runs/{$extraId}/board", $board);
    $completed[] = $extraId;
}

$profile = expect('POST /v1/profiles', $router->handle('POST', '/v1/profiles', ['run_ids' => $completed]), 200);
$profileSegment = $profile['segments'][0];
assertThat('one segment is returned', $profile['segments_count'] === 1, (string) $profile['segments_count']);
assertThat('the segment is eligible', $profileSegment['status'] === 'eligible', (string) $profileSegment['status']);
assertThat('three runs are aggregated', $profileSegment['runs'] === 3, (string) $profileSegment['runs']);
assertThat('the segment names its difficulty', $profileSegment['difficulty'] === 'leadership');
assertThat('the segment carries coverage by track', $profileSegment['coverage']['tracks'] !== []);
assertThat('merging is disclaimed', $profile['merged_across_versions'] === false);
printf(
    "  profile overall=%d  (runs: %d scenarios, %d tracks)\n",
    $profileSegment['overall'],
    $profileSegment['coverage']['distinct_scenarios'],
    count($profileSegment['coverage']['tracks'])
);

expect('POST /v1/profiles with no run_ids', $router->handle('POST', '/v1/profiles', []), 400);
expect('POST /v1/profiles with an empty list', $router->handle('POST', '/v1/profiles', ['run_ids' => []]), 400);
expect(
    'POST /v1/profiles below the window',
    $router->handle('POST', '/v1/profiles', ['run_ids' => [$runId]]),
    200
);
$short = $router->handle('POST', '/v1/profiles', ['run_ids' => [$runId]])[1];
assertThat('a single run yields no aggregate', $short['segments'][0]['status'] === 'insufficient' && $short['segments'][0]['overall'] === null);

printf("\n== Counterfactuals over HTTP ==\n");

$cf = expect(
    'GET /v1/runs/{id}/counterfactuals',
    $router->handle('GET', "/v1/runs/{$runId}/counterfactuals", []),
    200
);
assertThat('three stages are reported', count($cf['stages']) === 3);
assertThat('the counterfactual score equals the scored run', $cf['score'] === $scored['score']);
assertThat('each stage offers its two unchosen options', count($cf['stages'][0]['alternatives']) === 2);
assertThat('alternatives name real rule ids', (bool) preg_match('/^DR-\d{3}$/', (string) (($cf['stages'][0]['alternatives'][0]['rules_added'][0])
    ?? ($cf['stages'][0]['alternatives'][0]['rules_removed'][0] ?? 'DR-000'))));
printf("  %s\n", $cf['stages'][0]['alternatives'][0]['summary']);

expect(
    'counterfactuals require a scored run',
    $router->handle('GET', '/v1/runs/00000000-0000-4000-8000-000000000000/counterfactuals', []),
    404
);

printf("\n== Demonstration over HTTP ==\n");

$demoHttp = expect('GET /v1/demo', $router->handle('GET', '/v1/demo', []), 200);
assertThat('the demo is unscored', $demoHttp['scored'] === false);
assertThat('the demo is unpersisted', $demoHttp['persisted'] === false);
assertThat('the demo offers two stages', count($demoHttp['stages']) === 2);

$demoMechanism = expect(
    'POST /v1/demo/decide',
    $router->handle('POST', '/v1/demo/decide', ['stage_id' => 'S1', 'choice_id' => 'B']),
    200
);
assertThat('the demo returns rule firings', count($demoMechanism['mechanism']) > 0);
assertThat('the demo returns no score under any key', !isset($demoMechanism['score']) && !isset($demoMechanism['dimensions']));
assertThat('the demo says it is not a running score', str_contains((string) $demoMechanism['note'], 'not a running score'));
printf("  %s -> %s\n", $demoMechanism['choice_label'], implode(', ', array_column($demoMechanism['mechanism'], 'rule')));

expect('demo decide without a choice', $router->handle('POST', '/v1/demo/decide', ['stage_id' => 'S1']), 400);
expect('demo decide with an unknown stage', $router->handle('POST', '/v1/demo/decide', ['stage_id' => 'S9', 'choice_id' => 'A']), 400);

printf("\n== Security headers ==\n");

$headers = \BoardSim\Http\SecurityHeaders::forApi(false);
$csp = (string) $headers['Content-Security-Policy'];

assertThat('a content security policy is sent', $csp !== '');
assertThat('scripts may not be inlined', str_contains($csp, "script-src 'self'") && !str_contains($csp, "script-src 'self' 'unsafe-inline'"));
assertThat('eval is not permitted', !str_contains($csp, 'unsafe-eval'));
assertThat('plugins and objects are blocked', str_contains($csp, "object-src 'none'"));
assertThat('the base tag cannot be hijacked', str_contains($csp, "base-uri 'none'"));
assertThat('forms cannot post off-origin', str_contains($csp, "form-action 'self'"));
assertThat('the page cannot be framed', str_contains($csp, "frame-ancestors 'none'"));
assertThat('legacy framing is also blocked', $headers['X-Frame-Options'] === 'DENY');
assertThat('inline scripts are refused', str_contains($csp, "script-src 'self'") && !str_contains($csp, 'unsafe-inline'));
assertThat('inline style attributes are refused', str_contains($csp, "style-src 'self'") && str_contains($csp, "style-src-attr 'none'"));
assertThat('participant data is never cached', $headers['Cache-Control'] === 'no-store');
assertThat('HSTS is withheld over plain http', !isset($headers['Strict-Transport-Security']));
assertThat(
    'HSTS is set over https',
    isset(\BoardSim\Http\SecurityHeaders::forApi(true)['Strict-Transport-Security'])
);
assertThat(
    'https is detected from the proxy header too',
    (static function (): bool {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $detected = \BoardSim\Http\SecurityHeaders::requestIsHttps();
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        return $detected;
    })()
);

$htaccess = (string) file_get_contents($root . '/public/.htaccess');
preg_match('/Content-Security-Policy "([^"]+)"/', $htaccess, $mirror);
assertThat('the .htaccess carries a mirrored CSP', isset($mirror[1]));
assertThat(
    'the static and API policies are identical',
    ($mirror[1] ?? '') === $csp,
    (string) ($mirror[1] ?? '')
);
assertThat('the static policy keeps immutable asset caching', str_contains($htaccess, 'immutable'));
assertThat('dotfiles stay denied', str_contains($htaccess, 'Require all denied'));

// The routing contract: /v1 goes to the front controller, and never falls
// through to the SPA. A missing shim once made production answer /v1 with
// index.html and HTTP 200, which the browser reported as a JSON parse error.
assertThat(
    'the .htaccess routes /v1 to the front controller',
    str_contains($htaccess, 'RewriteRule ^v1(/.*)?$ api/index.php')
);
assertThat(
    'a missing api/index.php fails loudly instead of serving the SPA',
    str_contains($htaccess, 'RewriteRule ^api/index\.php$ - [R=503,L]')
);
assertThat(
    'the SPA fallback excludes /v1',
    str_contains($htaccess, 'RewriteCond %{REQUEST_URI} !^/v1(/|$)')
);

printf("\n== Erasure over HTTP ==\n");

$victim = expect(
    'POST /v1/runs for erasure',
    $router->handle('POST', '/v1/runs', ['scenario_id' => 'BS-007', 'difficulty' => 'leadership']),
    201
);
$victimId = (string) $victim['run_id'];

foreach (['S1' => 'C', 'S2' => 'C', 'S3' => 'C'] as $stage => $choice) {
    $router->handle('POST', "/v1/runs/{$victimId}/decisions", [
        'idempotency_key' => $victimId . '-' . $stage,
        'stage_id' => $stage,
        'choice_id' => $choice,
    ]);
}

$router->handle('POST', "/v1/runs/{$victimId}/board", $board);

$erased = expect('DELETE /v1/runs/{id}', $router->handle('DELETE', "/v1/runs/{$victimId}", []), 200);
assertThat('deletion is confirmed', $erased['deleted'] === true);
assertThat('deletion is not recoverable', $erased['recoverable'] === false);
assertThat('deletion removed the three events', $erased['events_removed'] === 3, (string) $erased['events_removed']);
expect('GET after DELETE is 404', $router->handle('GET', "/v1/runs/{$victimId}", []), 404);
expect('explain after DELETE is 404', $router->handle('GET', "/v1/runs/{$victimId}/explain", []), 404);
expect('DELETE twice is 404', $router->handle('DELETE', "/v1/runs/{$victimId}", []), 404);

printf("\n== Share links over HTTP ==\n");

$shareHttp = expect(
    'POST /v1/shares',
    $router->handle('POST', '/v1/shares', ['run_ids' => $completed]),
    201
);
assertThat('the token is 64 hex characters', preg_match('/^[0-9a-f]{64}$/', (string) $shareHttp['token']) === 1);
assertThat('the link expires in seven days', $shareHttp['expires_in_days'] === 7);

$resolvedHttp = expect('GET /v1/shares/{token}', $router->handle('GET', '/v1/shares/' . $shareHttp['token'], []), 200);
assertThat('the shared view is read only', $resolvedHttp['read_only'] === true);
assertThat('the shared view carries a profile', isset($resolvedHttp['profile']['segments']));

expect('DELETE /v1/shares/{token}', $router->handle('DELETE', '/v1/shares/' . $shareHttp['token'], []), 200);
expect('GET after revocation is 404', $router->handle('GET', '/v1/shares/' . $shareHttp['token'], []), 404);
expect('an unknown token is 404', $router->handle('GET', '/v1/shares/' . str_repeat('b', 64), []), 404);
expect('POST /v1/shares without run_ids', $router->handle('POST', '/v1/shares', []), 400);

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
