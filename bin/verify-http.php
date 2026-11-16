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

// Pinned before any output, exactly as the HTTP entry points pin it: PHP
// refuses session ini changes once output has started, which is why the
// entry-point position is part of the contract asserted below.
\BoardSim\Http\CookiePolicy::harden();

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

/**
 * Every script and stylesheet a document references must carry an integrity
 * attribute matching the sha384 digest of the bytes actually served — ZAP
 * flags an unpinned script or stylesheet ("Sub Resource Integrity Attribute
 * Missing"), and a stale pin breaks the page in the browser instead, so the
 * digest is recomputed here from disk rather than trusted.
 *
 * @param list<string> $documents
 */
function assertSubresourceIntegrity(string $label, array $documents, string $assetBase): void
{
    $tags = [];

    foreach ($documents as $document) {
        if (preg_match_all('#<script\b[^>]*\bsrc="[^"]*"[^>]*>#', $document, $scripts) === 1) {
            foreach ($scripts[0] as $tag) {
                $tags[] = [$label, $tag];
            }
        }

        if (preg_match_all('#<link\b[^>]*\brel="stylesheet"[^>]*>#', $document, $styles) === 1) {
            foreach ($styles[0] as $tag) {
                $tags[] = [$label, $tag];
            }
        }
    }

    if ($tags === []) {
        assertThat($label . ' reference at least one script or stylesheet', false);

        return;
    }

    foreach ($tags as [$documentLabel, $tag]) {
        preg_match('#\b(?:src|href)="([^"]+)"#', $tag, $url);
        $reference = (string) ($url[1] ?? '');

        if (!str_starts_with($reference, '/')) {
            assertThat(sprintf('%s: %s is same-origin', $documentLabel, $reference), false);

            continue;
        }

        $file = rtrim($assetBase, '/') . $reference;
        $digest = is_file($file)
            ? 'sha384-' . base64_encode((string) hash('sha384', (string) file_get_contents($file), true))
            : null;
        preg_match('#integrity="([^"]+)"#', $tag, $pin);

        assertThat(
            sprintf('%s pins %s', $documentLabel, $reference),
            $digest !== null && isset($pin[1]) && $pin[1] === $digest,
            isset($pin[1]) ? 'stale digest' : 'no integrity attribute'
        );
    }
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

$headers = \BoardSim\Http\SecurityHeaders::forApi();
$csp = (string) $headers['Content-Security-Policy'];

assertThat('a content security policy is sent', $csp !== '');
assertThat('the policy denies by default', str_contains($csp, "default-src 'self'"));
assertThat('scripts may not be inlined', str_contains($csp, "script-src 'self'") && !str_contains($csp, "script-src 'self' 'unsafe-inline'"));
assertThat('eval is not permitted', !str_contains($csp, 'unsafe-eval'));
assertThat('plugins and objects are blocked', str_contains($csp, "object-src 'none'"));

// base-uri, form-action and frame-ancestors have no fallback to default-src,
// so each is asserted on its own: a policy that omits one is read as allowing
// anything for it, which is what ZAP's "CSP: Failure to Define Directive with
// No Fallback" (10055-13) measures.
assertThat('the base tag cannot be hijacked', str_contains($csp, "base-uri 'none'"));
assertThat('forms cannot post off-origin', str_contains($csp, "form-action 'self'"));
assertThat('the page cannot be framed', str_contains($csp, "frame-ancestors 'none'"));
assertThat('legacy framing is also blocked', $headers['X-Frame-Options'] === 'DENY');
assertThat('inline scripts are refused', str_contains($csp, "script-src 'self'") && !str_contains($csp, 'unsafe-inline'));
assertThat('inline style attributes are refused', str_contains($csp, "style-src 'self'") && str_contains($csp, "style-src-attr 'none'"));
assertThat('participant data is never cached', $headers['Cache-Control'] === 'no-store');

// The origin states same-origin isolation explicitly instead of relying on
// the absence of a CORS header (ZAP 10098: cross-domain misconfiguration).
assertThat('cross-origin reads of origin resources are refused', $headers['Cross-Origin-Resource-Policy'] === 'same-origin');
assertThat('cross-origin window access is refused', $headers['Cross-Origin-Opener-Policy'] === 'same-origin');
assertThat('no CORS header loosens the same-origin policy', !preg_grep('/^Access-Control/i', array_keys($headers)));

// HSTS on every response, http or https alike: a browser ignores the header
// over plain HTTP (RFC 6797), so unconditional emission cannot lock an origin
// out of anything — and behind a TLS-terminating proxy the origin may see
// plain HTTP even though the browser sees HTTPS, which is exactly how the /v1
// responses used to go out without the header.
$hsts = (string) ($headers['Strict-Transport-Security'] ?? '');
assertThat('HSTS is sent on every response', str_contains($hsts, 'max-age=31536000') && str_contains($hsts, 'includeSubDomains'), $hsts ?: 'absent');

$staticHeaders = \BoardSim\Http\SecurityHeaders::forStatic();
assertThat(
    'the static channel carries the same policy minus the JSON body rules',
    $staticHeaders == array_diff_key($headers, ['Content-Type' => 1, 'Cache-Control' => 1])
);

$htaccess = (string) file_get_contents($root . '/public/.htaccess');
preg_match('/Content-Security-Policy "([^"]+)"/', $htaccess, $mirror);
assertThat('the .htaccess carries a mirrored CSP', isset($mirror[1]));
assertThat(
    'the static and API policies are identical',
    ($mirror[1] ?? '') === $csp,
    (string) ($mirror[1] ?? '')
);

// Apache serves the SPA, the assets, the discovery files and the error pages
// without PHP, so every always-set header of the PHP channel must appear in
// .htaccess too — one policy, two delivery channels, no drift.
foreach ([
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => \BoardSim\Http\SecurityHeaders::PERMISSIONS_POLICY,
    'Cross-Origin-Resource-Policy' => 'same-origin',
    'Cross-Origin-Opener-Policy' => 'same-origin',
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
] as $name => $value) {
    assertThat(
        sprintf('the .htaccess always sets %s', $name),
        str_contains($htaccess, sprintf('Header always set %s "%s"', $name, $value))
    );
}

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

// The Cloudflare origin lock is always on (requirements/12_CLOUDFLARE_ORIGIN_LOCK.md).
// Asserted as text, because the failure is silent in the worst direction: with the
// rule gone the site works perfectly for everyone, including a client that
// resolved the origin address and skipped Cloudflare entirely. The value itself is
// a placeholder here — bin/build-deploy.php substitutes the real one and refuses
// to build without it, which is the only reason a secret can live in .htaccess.
$originGuard = strpos($htaccess, 'RewriteCond %{HTTP:X-Origin-Secret} !^__CF_ORIGIN_SECRET__$');
$apiRoute = strpos($htaccess, 'RewriteRule ^v1(/.*)?$ api/index.php');

assertThat('the origin refuses traffic that did not come through Cloudflare', $originGuard !== false);
assertThat(
    'the origin lock is the first rewrite rule, before the API route',
    $originGuard !== false && $apiRoute !== false && $originGuard < $apiRoute
);
assertThat('the origin lock denies with 403', $originGuard !== false
    && str_contains($htaccess, 'RewriteRule ^ - [F,L]'));

// Let's Encrypt and cPanel AutoSSL fetch the challenge from the origin address,
// never through Cloudflare, so the CA cannot present the header. The exemption is
// therefore load-bearing for certificate renewal — and it must come BEFORE the
// lock, or it exempts nothing.
$acmeExemption = strpos($htaccess, 'RewriteRule ^\\.well-known(/|$) - [L]');

assertThat(
    'the ACME validation path is exempt from the origin lock',
    $acmeExemption !== false && str_contains($htaccess, 'RewriteRule ^pki-validation(/|$) - [L]')
);
assertThat(
    'the ACME exemption is evaluated before the lock',
    $acmeExemption !== false && $originGuard !== false && $acmeExemption < $originGuard
);

// Branded error documents: Apache's default page is the one screen that never
// looked like this product, and it is the screen an operator sees first when a
// deploy is wrong.
foreach (['400', '403', '404', '500', '503'] as $status) {
    assertThat(
        "the {$status} error document is branded and installed",
        str_contains($htaccess, "ErrorDocument {$status} /errors/{$status}.html")
        && is_file($root . "/public/errors/{$status}.html")
    );
}

assertThat(
    'the error pages are styled by one shipped stylesheet',
    is_file($root . '/public/errors/error.css')
    && str_contains((string) file_get_contents($root . '/public/errors/404.html'), '/errors/error.css')
);
assertThat(
    'no error page inlines style or script, which the CSP would refuse',
    (static function () use ($root): bool {
        foreach (glob($root . '/public/errors/*.html') ?: [] as $page) {
            $html = (string) file_get_contents($page);

            if (str_contains($html, 'style=') || str_contains($html, '<script')) {
                return false;
            }
        }

        return true;
    })()
);

// The licence the site itself serves: Apache-2.0 for the code a browser downloads,
// plus the MIT notice that travels inside the bundle.
assertThat(
    'the served licence file carries both notices',
    is_file($root . '/public/licences.txt')
    && str_contains((string) file_get_contents($root . '/public/licences.txt'), 'Apache License')
    && str_contains((string) file_get_contents($root . '/public/licences.txt'), 'MIT License')
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

printf("\n== Cookie policy ==\n");

// The policy was hardened at the top of this script, before any output — the
// same position the HTTP entry points pin it in. The product sets no cookies:
// no login, a stateless API, runs addressed by unguessable UUIDs. The policy
// exists so that stays true structurally: nothing outside it may set a cookie
// or start a session, and a session something else starts (a host php.ini, a
// future feature) inherits hardened attributes before any cookie can exist.
$params = session_get_cookie_params();
assertThat('a session cookie would be HttpOnly', $params['httponly'] === true);
assertThat('a session cookie would be Secure', $params['secure'] === true);
assertThat('a session cookie would be SameSite=Lax', $params['samesite'] === 'Lax');
assertThat('a session cookie would stay on the site path', $params['path'] === '/');
assertThat('a session cookie would stay host-only', $params['domain'] === '');
assertThat('session ids cannot travel in URLs', ini_get('session.use_trans_sid') === '0');
assertThat('session ids are accepted from cookies only', ini_get('session.use_only_cookies') === '1');
assertThat('foreign session ids are rejected', ini_get('session.use_strict_mode') === '1');

$forced = \BoardSim\Http\CookiePolicy::attributes([
    'lifetime' => 3600,
    'secure' => false,
    'httponly' => false,
    'samesite' => 'None',
    'path' => '/evil',
    'domain' => '.example.net',
]);
assertThat('the policy forces HttpOnly whatever a caller asks', $forced['httponly'] === true);
assertThat('the policy forces Secure whatever a caller asks', $forced['secure'] === true);
assertThat('the policy forces SameSite=Lax whatever a caller asks', $forced['samesite'] === 'Lax');
assertThat('the policy pins the cookie path', $forced['path'] === '/');
assertThat('the policy keeps the cookie host-only', $forced['domain'] === '');
assertThat('a caller may only choose the lifetime', $forced['lifetime'] === 3600);

$cookieOffenders = (static function () use ($root): array {
    $offenders = [];
    $directories = [$root . '/src', $root . '/public', $root . '/deploy', $root . '/bin', $root . '/tools'];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (str_ends_with($path, '/src/Http/CookiePolicy.php')) {
                continue;
            }

            if (preg_match('/\bsetcookie\s*\(|\bsession_start\s*\(|Set-[Cc]ookie:/', (string) file_get_contents($path)) === 1) {
                $offenders[] = substr($path, strlen($root) + 1);
            }
        }
    }

    return $offenders;
})();

assertThat(
    'no code outside CookiePolicy sets a cookie or starts a session',
    $cookieOffenders === [],
    implode(', ', $cookieOffenders)
);

foreach (['public/api/index.php', 'web/dev-router.php', 'deploy/smoke-router.php'] as $entryPoint) {
    assertThat(
        sprintf('%s hardens the cookie policy before anything answers', $entryPoint),
        str_contains((string) file_get_contents($root . '/' . $entryPoint), 'CookiePolicy::harden()')
    );
}

printf("\n== Redirects ==\n");

$smokeRouter = (string) file_get_contents($root . '/deploy/smoke-router.php');
preg_match("#if \(\\\$path === '/app/index\.html'\) \{(.*?)\n\}#s", $smokeRouter, $redirect);

assertThat('the smoke router mirrors the duplicate-shell redirect', isset($redirect[1]));
assertThat(
    'the mirrored redirect is a 301 to the canonical root',
    isset($redirect[1])
    && str_contains($redirect[1], 'http_response_code(301)')
    && str_contains($redirect[1], "header('Location: /')")
);
assertThat(
    'the mirrored redirect carries no body (ZAP 10044 compares the body with Location+300)',
    isset($redirect[1]) && !preg_match('/\b(?:echo|print|readfile|include|require)\b/', $redirect[1])
);

assertThat(
    'Apache answers the duplicate shell from the zero-body redirector',
    str_contains($htaccess, 'RewriteCond %{THE_REQUEST} \s/+app/index\.html[\s?] [NC]')
    && str_contains($htaccess, 'RewriteRule ^app/index\.html$ redirect-to-root.php [L]')
);
assertThat(
    'the redirector emits a status and a Location and nothing else',
    is_file($root . '/public/redirect-to-root.php')
    && str_contains((string) file_get_contents($root . '/public/redirect-to-root.php'), "header('Location: /');")
    && !preg_match('/\b(?:echo|print|readfile|include|require)\b/', (string) file_get_contents($root . '/public/redirect-to-root.php'))
);

printf("\n== Failure branches carry the policy ==\n");

$frontController = (string) file_get_contents($root . '/public/api/index.php');
$shim = (string) file_get_contents($root . '/deploy/api-index-shim.php');
$cspHeaderLine = 'Content-Security-Policy: ' . $csp;

foreach ([
    'the front controller 500' => $frontController,
    'the deploy shim 500' => $shim,
    'the smoke router mirror' => $smokeRouter,
] as $where => $source) {
    assertThat(
        sprintf('%s carries the full header set', $where),
        str_contains($source, "header('X-Content-Type-Options: nosniff')")
            && str_contains($source, "header('X-Frame-Options: DENY')")
            && str_contains($source, $cspHeaderLine)
            && str_contains($source, "header('Strict-Transport-Security: max-age=31536000; includeSubDomains')")
    );
}

assertThat(
    'the deploy shim 500 no longer publishes filesystem paths or PHP settings',
    !str_contains($shim, "'open_basedir' =>")
    && !str_contains($shim, "'tried' =>")
);

printf("\n== Subresource integrity ==\n");

$errorPages = glob($root . '/public/errors/*.html') ?: [];
assertThat('the five error pages are present', count($errorPages) === 5, (string) count($errorPages));
assertSubresourceIntegrity(
    'the error pages',
    array_map(static fn (string $page): string => (string) file_get_contents($page), $errorPages),
    $root . '/public'
);

$builtShell = $root . '/public/app/index.html';

if (is_file($builtShell)) {
    assertSubresourceIntegrity('the built shell', [(string) file_get_contents($builtShell)], $root . '/public');
} else {
    // public/app/ is generated and gitignored: a fresh clone legitimately has
    // no built shell. bin/verify-meta.php --require-build covers the pins in
    // the CI job that runs right after npm run build.
    printf("  SKIP  the built shell is not built — npm run build pins its assets\n");
}

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
