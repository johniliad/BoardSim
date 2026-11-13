<?php

declare(strict_types=1);

/**
 * Post-deployment check of a running BoardSim origin.
 *
 *   php bin/verify-deploy.php --base=https://boardsim.iliadis.net
 *   php bin/verify-deploy.php --base=http://127.0.0.1:8100
 *
 * DEPLOYMENT.md section 6 asks for a curl and a hand-walk through a run; this is
 * the part a machine can do. It asserts the things that fail silently:
 *
 *   - /v1 is answered by PHP, not by the SPA fallback (the failure that surfaced
 *     in the browser as "Unexpected token '<', "<!doctype "... is not valid JSON");
 *   - the health payload reports every pinned artefact and is not in dev mode;
 *   - unknown /v1 routes answer with JSON 404 rather than an HTML page;
 *   - the deployed bundle is the one built from this checkout;
 *   - the security headers from public/.htaccess are actually in force;
 *   - dotfiles are denied.
 *
 * Read-only: it creates nothing, so it is safe to point at production. Exit code
 * 0 only when every check passes.
 */
$root = dirname(__DIR__);
$base = '';
$configPath = '';
$requireReady = false;
$expectRelease = '';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = substr($arg, strlen('--base='));
    }

    if (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, strlen('--config='));
    }

    if (str_starts_with($arg, '--expect-release=')) {
        $expectRelease = substr($arg, strlen('--expect-release='));
    }

    if ($arg === '--require-ready') {
        $requireReady = true;
    }
}

// --config=deploy/host.config.php saves repeating the origin (and gives the
// exact value that bin/build-deploy.php baked into the artefacts).
if ($base === '' && $configPath !== '' && is_file($configPath)) {
    $hostConfig = require $configPath;

    if (is_array($hostConfig)) {
        $base = (string) ($hostConfig['release_url'] ?? $hostConfig['domain'] ?? '');
    }
}

$base = rtrim($base, '/');

if ($base !== '' && preg_match('#^https?://#', $base) !== 1) {
    $base = 'https://' . $base;
}

if ($base === '' || preg_match('#^https?://[^/]+#', $base) !== 1) {
    fwrite(STDERR, "Usage: php bin/verify-deploy.php --base=https://boardsim.example.net [--require-ready] [--expect-release=<sha>]\n");
    fwrite(STDERR, "       php bin/verify-deploy.php --config=deploy/host.config.php [--require-ready] [--expect-release=<sha>]\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$warned = 0;

/**
 * @param array{status: int, headers: array<string, string>, body: string, error: string|null} $response
 */
function expect(string $label, bool $ok, array $response = [], string $detail = ''): bool
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("  PASS  %s\n", $label);

        return true;
    }

    $failed++;

    $suffix = $detail;

    if ($suffix === '' && $response !== []) {
        $suffix = sprintf(
            'HTTP %d, %s%s',
            $response['status'],
            $response['headers']['content-type'] ?? 'no content type',
            $response['error'] !== null ? ', ' . $response['error'] : ''
        );
    }

    printf("  FAIL  %s%s\n", $label, $suffix !== '' ? ' — ' . $suffix : '');

    return false;
}

function warn(string $label, string $detail): void
{
    global $warned;

    $warned++;
    printf("  WARN  %s — %s\n", $label, $detail);
}

/**
 * True when a response is the SPA shell rather than the static file that was asked
 * for. The SPA fallback answers *any* path with index.html and HTTP 200, so a
 * missing robots.txt looks like a successful request until you read the bytes —
 * which is how a deploy without the discovery files passed every check that only
 * asked for a status code.
 *
 * @param array{status: int, headers: array<string, string>, body: string, error: string|null} $response
 */
function answeredBySpaFallback(array $response): bool
{
    return str_contains($response['headers']['content-type'] ?? '', 'text/html')
        || str_contains($response['body'], '<!doctype html');
}

/**
 * Minimal GET client. Three transports, because the machine that runs the check
 * is not always the machine that serves it:
 *
 *   1. the curl extension, when present;
 *   2. PHP streams, when the URL is http or openssl is loaded;
 *   3. the curl command-line tool, which is how a Windows developer box without
 *      php-curl still reaches the https origin.
 *
 * @return array{status: int, headers: array<string, string>, body: string, error: string|null}
 */
function httpGet(string $url): array
{
    if (function_exists('curl_init')) {
        return curlGet($url);
    }

    if (str_starts_with($url, 'http://') || extension_loaded('openssl')) {
        return streamGet($url);
    }

    return curlCliGet($url);
}

/**
 * @return array{status: int, headers: array<string, string>, body: string, error: string|null}
 */
function curlGet(string $url): array
{
    $status = 0;
    $headers = [];
    $ingest = ingestLine($status, $headers);

    $handle = curl_init($url);

    if ($handle === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl_init failed'];
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'BoardSim-verify-deploy/1.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use ($ingest): int {
            $ingest($line);

            return strlen($line);
        },
    ]);

    $body = curl_exec($handle);
    $error = curl_error($handle);
    curl_close($handle);

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => is_string($body) ? $body : '',
        'error' => $error !== '' ? $error : null,
    ];
}

/**
 * @return array{status: int, headers: array<string, string>, body: string, error: string|null}
 */
function streamGet(string $url): array
{
    $status = 0;
    $headers = [];
    $ingest = ingestLine($status, $headers);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 25,
            'ignore_errors' => true,
            // Do not follow redirects: a verification must see the status the
            // origin actually returns, or a 301 to the canonical address would
            // look like a 200 and pass silently.
            'follow_location' => 0,
            'user_agent' => 'BoardSim-verify-deploy/1.0',
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $rawHeaders = $http_response_header ?? [];

    foreach (is_array($rawHeaders) ? $rawHeaders : [] as $line) {
        $ingest($line);
    }

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? 'request failed' : null,
    ];
}

/**
 * @return array{status: int, headers: array<string, string>, body: string, error: string|null}
 */
function curlCliGet(string $url): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open(
        ['curl', '-sS', '-i', '--max-time', '25', '--user-agent', 'BoardSim-verify-deploy/1.0', $url],
        $descriptors,
        $pipes
    );

    if (!is_resource($process)) {
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'error' => 'no HTTPS transport: enable php-curl or php-openssl, or install the curl command',
        ];
    }

    $raw = (string) stream_get_contents($pipes[1]);
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    if ($raw === '') {
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'error' => $stderr !== '' ? $stderr : 'curl exited with ' . $code,
        ];
    }

    [$status, $headers] = splitHead($raw);

    return [
        'status' => $status,
        'headers' => $headers,
        'body' => (string) preg_replace('/^.*?\r?\n\r?\n/s', '', $raw, 1),
        'error' => null,
    ];
}

/**
 * @param array<string, string> $headers
 */
function ingestLine(int &$status, array &$headers): Closure
{
    return static function (string $line) use (&$status, &$headers): void {
        $line = trim($line);

        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
            $headers = [];

            return;
        }

        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    };
}

/**
 * @return array{0: int, 1: array<string, string>}
 */
function splitHead(string $raw): array
{
    $head = (string) preg_replace('/\r?\n\r?\n.*$/s', '', $raw, 1);
    $status = 0;
    $headers = [];

    foreach (preg_split('/\r?\n/', $head) ?: [] as $line) {
        if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
            $headers = [];

            continue;
        }

        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    return [$status, $headers];
}

/**
 * @param array{status: int, headers: array<string, string>, body: string, error: string|null} $response
 * @return array<string, mixed>|null
 */
function jsonOf(array $response): ?array
{
    $decoded = json_decode($response['body'], true);

    return is_array($decoded) ? $decoded : null;
}

printf("BoardSim deployment check — %s\n", $base);

printf("\n== API routing ==\n");

$health = httpGet($base . '/v1/health');

// A missing transport is a property of this machine, not of the deployment: say
// so once instead of failing sixteen checks with the same sentence.
if ($health['status'] === 0 && str_contains((string) $health['error'], 'no HTTPS transport')) {
    fwrite(STDERR, "\n" . $health['error'] . "\n");
    exit(2);
}

$healthType = $health['headers']['content-type'] ?? '';

$reachable = expect(
    'GET /v1/health is answered by the API, not by the SPA fallback',
    $health['status'] === 200 && str_contains($healthType, 'application/json'),
    $health,
    sprintf(
        'HTTP %d, %s — the document root needs api/index.php plus api/backend.php '
            . '(docs/deploy-cpanel.md)',
        $health['status'],
        $healthType !== '' ? $healthType : 'no content type'
    )
);

$healthJson = $reachable ? jsonOf($health) : null;

expect(
    'GET /v1/health returns a JSON object',
    $healthJson !== null,
    $health,
    'body is not a JSON object, so something other than the front controller answered'
);

$missing = [];

if ($healthJson !== null) {
    $pins = ['ruleset_version', 'policy_sha256', 'regulatory_bundle_sha256', 'content_version', 'content_sha256'];
    $missing = array_values(array_filter($pins, static fn (string $key): bool => !isset($healthJson[$key])));

    expect(
        'the health payload pins the ruleset, policy, regulatory bundle and content',
        $missing === [],
        [],
        'missing: ' . implode(', ', $missing)
    );

    $devMode = ($healthJson['mode'] ?? null) === 'dev';
    $loopback = preg_match('#^https?://(?:127\.0\.0\.1|localhost|\[::1\])(?::\d+)?$#', $base) === 1;

    expect(
        'the deployment is not in development mode',
        !$devMode || $loopback,
        [],
        'mode=dev: BOARDSIM_DEV must never be set on a public host'
    );

    if ($devMode && $loopback) {
        printf("        note: mode=dev on a loopback origin, which is expected locally\n");
    }

    expect(
        'the integrity controls are reported',
        is_array($healthJson['integrity_controls'] ?? null) && $healthJson['integrity_controls'] !== [],
        [],
        'integrity_controls is empty: the store is not reporting its guarantees'
    );

    if ($missing === []) {
        printf(
            "        ruleset %s · policy %s · regulatory %s · content %s\n",
            (string) $healthJson['ruleset_version'],
            substr((string) $healthJson['policy_sha256'], 0, 8),
            substr((string) $healthJson['regulatory_bundle_sha256'], 0, 8),
            (string) $healthJson['content_version']
        );
    }

    // Deployment state, not artefact pins: /v1/health is the only channel an
    // operator on a host without shell access has, so assert what it reports.
    $deployment = is_array($healthJson['deployment'] ?? null) ? $healthJson['deployment'] : [];
    $database = is_array($deployment['database'] ?? null) ? $deployment['database'] : [];
    $releaseInfo = is_array($deployment['release'] ?? null) ? $deployment['release'] : [];
    $bundles = is_array($deployment['bundles'] ?? null) ? $deployment['bundles'] : [];
    $status = (string) ($healthJson['status'] ?? '');

    if ($status !== '') {
        $bundleText = ($bundles['expected'] ?? null) === null
            ? 'n/a'
            : (string) ($bundles['seeded'] ?? '?') . '/' . (string) $bundles['expected'];

        printf(
            "        release %s · schema %s · bundles %s\n",
            (string) ($releaseInfo['version'] ?? 'unreleased'),
            (string) ($database['schema'] ?? 'n/a'),
            $bundleText
        );
    }

    if ($requireReady) {
        expect(
            'the deployment reports status ok',
            $status === 'ok',
            [],
            'status=' . ($status === '' ? 'missing' : $status)
                . ', schema=' . ($database['schema'] ?? 'missing')
                . (!empty($database['hint']) ? ' — ' . (string) $database['hint'] : '')
        );
    } elseif ($status === 'degraded') {
        warn(
            'the deployment reports a degraded status',
            (string) ($database['hint'] ?? 'see /v1/health')
        );
    }

    if ($expectRelease !== '') {
        $actual = (string) ($releaseInfo['sha256'] ?? '');

        expect(
            'the origin runs the release that was built',
            $actual === $expectRelease,
            [],
            $actual === ''
                ? 'the origin reports no release stamp (an unreleased checkout)'
                : "origin serves {$actual}, expected {$expectRelease}"
        );
    }

    expect(
        'the health response leaks no PHP diagnostics',
        preg_match('/(?:Fatal error|Parse error|Warning:|Stack trace|BOOTSTRAP_FAILED)/', $health['body']) !== 1,
        [],
        'the API reports an internal failure; read the PHP error log on the host'
    );
}

$scenarios = httpGet($base . '/v1/scenarios');
$scenariosJson = jsonOf($scenarios);
$count = is_array($scenariosJson['scenarios'] ?? null) ? count($scenariosJson['scenarios']) : 0;

expect(
    'GET /v1/scenarios returns the pinned catalogue',
    $scenarios['status'] === 200 && $count >= 14,
    $scenarios,
    sprintf('HTTP %d with %d scenarios; the catalogue expects 14', $scenarios['status'], $count)
);

$demo = httpGet($base . '/v1/demo');
$demoJson = jsonOf($demo);

expect(
    'GET /v1/demo is served and reports scored=false',
    $demo['status'] === 200 && ($demoJson['scored'] ?? null) === false,
    $demo,
    'the public demonstration must never be scored'
);

// The glossary is derived from the same pinned artefacts, so it is part of the
// deployment contract: a deployment whose help text cannot load is a deployment
// where every code in the interface is unexplained.
$glossaryResponse = httpGet($base . '/v1/glossary');
$glossaryJson = jsonOf($glossaryResponse);
$dimensionCount = is_array($glossaryJson['dimensions'] ?? null) ? count($glossaryJson['dimensions']) : 0;
$ruleCount = is_array($glossaryJson['rules'] ?? null) ? count($glossaryJson['rules']) : 0;

expect(
    'GET /v1/glossary serves the derived glossary',
    $glossaryResponse['status'] === 200 && $dimensionCount === 10 && $ruleCount >= 47,
    $glossaryResponse,
    sprintf('HTTP %d with %d dimensions and %d rules', $glossaryResponse['status'], $dimensionCount, $ruleCount)
);

$unknown = httpGet($base . '/v1/this-route-does-not-exist');
$unknownType = $unknown['headers']['content-type'] ?? '';
$unknownJson = jsonOf($unknown);

expect(
    'an unknown /v1 route answers JSON 404, not an HTML page',
    $unknown['status'] === 404
        && str_contains($unknownType, 'application/json')
        && ($unknownJson['error'] ?? null) === 'NOT_FOUND',
    $unknown,
    sprintf(
        'HTTP %d %s — /v1 is not reaching src/Http/ApiRouter.php',
        $unknown['status'],
        $unknownType !== '' ? $unknownType : 'no content type'
    )
);


printf("\n== SPA ==\n");

$index = httpGet($base . '/');

expect(
    'GET / serves the SPA shell',
    $index['status'] === 200 && str_contains($index['body'], 'id="root"'),
    $index,
    sprintf(
        'HTTP %d, %s — the document root must serve public/app/index.html for the SPA routes',
        $index['status'],
        $index['headers']['content-type'] ?? 'no content type'
    )
);

preg_match('#/app/assets/index-[A-Za-z0-9_-]+\.js#', $index['body'], $deployedAsset);
$deployed = $deployedAsset[0] ?? '';
$asset = $deployed === ''
    ? ['status' => 0, 'headers' => [], 'body' => '', 'error' => null]
    : httpGet($base . $deployed);

expect(
    'the shell references a built bundle that the origin actually serves',
    $deployed !== ''
        && $asset['status'] === 200
        && str_contains($asset['headers']['content-type'] ?? '', 'javascript'),
    $asset,
    $deployed === ''
        ? 'no /app/assets/index-*.js reference in the shell'
        : sprintf(
            '%s returned HTTP %d (%s)',
            $deployed,
            $asset['status'],
            $asset['headers']['content-type'] ?? 'no content type'
        )
);

$localIndex = $root . '/public/app/index.html';

if (is_file($localIndex)) {
    $localShell = (string) file_get_contents($localIndex);
    preg_match('#/app/assets/index-[A-Za-z0-9_-]+\.js#', $localShell, $localAsset);
    $expected = $localAsset[0] ?? '';

    if ($expected !== '' && $deployed !== '' && $expected !== $deployed) {
        // A stale bundle explains "the fix did not work" after a correct backend
        // deployment far more often than a real regression does.
        warn(
            'the deployed bundle is not the one in this checkout',
            sprintf('origin serves %s, this checkout built %s — re-upload public/app/', $deployed, $expected)
        );
    } else {
        expect(
            'the deployed bundle matches this checkout',
            $expected !== '' && $expected === $deployed,
            [],
            $expected === ''
                ? 'no local build found: run cd web && npm run build'
                : 'the origin served no shell, so no bundle could be compared'
        );
    }

    // The bundle hash is not a release marker: a release that changes only PHP,
    // HTML or .htaccess leaves the JS asset name identical, so a docroot two
    // releases old compares equal on hashes and looks deployed. The shell's own
    // bytes are the only thing that changes every time, so compare those.
    expect(
        'the served shell is byte-for-byte the one in this checkout',
        $index['status'] === 200
            && $localShell !== ''
            && hash_equals(hash('sha256', $localShell), hash('sha256', $index['body'])),
        [],
        sprintf(
            'origin served %d bytes of shell, this checkout built %d — the docroot '
            . 'is a different release even if the asset hash matches',
            strlen($index['body']),
            strlen($localShell)
        )
    );
}

printf("\n== Discovery ==\n");

preg_match('#<link rel="canonical" href="([^"]+)"#', $index['body'], $canonicalMatch);
$canonical = $canonicalMatch[1] ?? '';

expect(
    'the shell declares its canonical URL',
    $canonical !== '' && str_starts_with($canonical, 'https://'),
    [],
    'no <link rel="canonical"> in the served HTML'
);

$ogTitle = 'BoardSim | Executive decision-making under pressure';

expect(
    'the shell carries the social and structured-data identity',
    str_contains($index['body'], '<title>' . $ogTitle . '</title>')
        && str_contains($index['body'], 'property="og:site_name" content="BoardSim"')
        && str_contains($index['body'], '"@type": "WebApplication"')
        && str_contains($index['body'], 'name="author" content="Dr. John Iliadis"'),
    [],
    'title, og:site_name, author or JSON-LD missing from the served HTML: '
        . 'web/index.html must carry them, not inject them at runtime'
);

$robots = httpGet($base . '/robots.txt');

expect(
    'robots.txt is served as text and names the sitemap',
    $robots['status'] === 200
        && !answeredBySpaFallback($robots)
        && str_contains($robots['headers']['content-type'] ?? '', 'text/plain')
        && $canonical !== ''
        && str_contains($robots['body'], 'Sitemap: ' . $canonical . 'sitemap.xml'),
    $robots,
    sprintf(
        'HTTP %d, %s, %d bytes — upload robots.txt to the document root',
        $robots['status'],
        $robots['headers']['content-type'] ?? 'no content type',
        strlen($robots['body'])
    )
);

$sitemap = httpGet($base . '/sitemap.xml');

expect(
    'sitemap.xml is served and its lastmod was stamped at build time',
    $sitemap['status'] === 200
        && !answeredBySpaFallback($sitemap)
        && str_contains($sitemap['headers']['content-type'] ?? '', 'xml')
        && str_contains($sitemap['body'], '<urlset')
        && str_contains($sitemap['body'], '<loc>' . $canonical . '</loc>')
        && !str_contains($sitemap['body'], '__LASTMOD__'),
    $sitemap,
    sprintf(
        'HTTP %d, %s, %d bytes — a __LASTMOD__ placeholder means the file was taken '
        . 'from public/ instead of the built docroot; text/html means it is not on disk',
        $sitemap['status'],
        $sitemap['headers']['content-type'] ?? 'no content type',
        strlen($sitemap['body'])
    )
);

$llms = httpGet($base . '/llms.txt');

expect(
    'llms.txt is served as text and points at the API',
    $llms['status'] === 200
        && !answeredBySpaFallback($llms)
        && str_contains($llms['headers']['content-type'] ?? '', 'text/plain')
        && str_starts_with($llms['body'], '# BoardSim')
        && str_contains($llms['body'], '/v1/glossary'),
    $llms,
    sprintf(
        'HTTP %d, %s, %d bytes — upload llms.txt to the document root',
        $llms['status'],
        $llms['headers']['content-type'] ?? 'no content type',
        strlen($llms['body'])
    )
);

// The three files above share one failure mode, so state it once: the SPA
// fallback answering a static path is a deploy that never copied the files.
expect(
    'no discovery file is answered by the SPA fallback',
    !answeredBySpaFallback($robots) && !answeredBySpaFallback($sitemap) && !answeredBySpaFallback($llms),
    [],
    'robots.txt, sitemap.xml and llms.txt must exist at the origin root; the SPA '
        . 'fallback answering them with index.html is why a crawler reports 403'
);

$duplicate = httpGet($base . '/app/index.html');

expect(
    'the duplicate shell address redirects to the canonical one',
    in_array($duplicate['status'], [301, 302, 308], true),
    $duplicate,
    sprintf(
        'HTTP %d — without the redirect a crawler indexes the same HTML twice',
        $duplicate['status']
    )
);

printf("\n== Security headers ==\n");

$headers = $index['headers'];
$expectations = [
    'x-content-type-options' => 'nosniff',
    'x-frame-options' => 'DENY',
    'referrer-policy' => 'strict-origin-when-cross-origin',
];

foreach ($expectations as $name => $value) {
    expect(
        sprintf('header %s is %s', $name, $value),
        str_contains($headers[$name] ?? '', $value),
        [],
        sprintf('got "%s"; public/.htaccess is not in force (AllowOverride All?)', $headers[$name] ?? 'nothing')
    );
}

expect(
    'the Content-Security-Policy is the same-origin policy',
    str_contains($headers['content-security-policy'] ?? '', "default-src 'self'")
        && str_contains($headers['content-security-policy'] ?? '', "object-src 'none'"),
    [],
    sprintf('got "%s"', $headers['content-security-policy'] ?? 'nothing')
);

if (str_starts_with($base, 'https://')) {
    expect(
        'HSTS is set on the HTTPS origin',
        str_contains($headers['strict-transport-security'] ?? '', 'max-age='),
        [],
        'got nothing: the vhost must terminate HTTPS for HSTS to be meaningful'
    );
}

printf("\n== Hardening ==\n");

$dotfile = httpGet($base . '/.htaccess');

expect(
    'dotfiles are denied',
    in_array($dotfile['status'], [403, 404], true),
    $dotfile,
    sprintf('HTTP %d: public/.htaccess is not denying dotfiles', $dotfile['status'])
);

printf("\n%d passed, %d failed, %d warnings\n", $passed, $failed, $warned);

exit($failed === 0 ? 0 : 1);

