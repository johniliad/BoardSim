<?php

declare(strict_types=1);

/**
 * BoardSim API front controller.
 *
 * The server is authoritative: the client submits a choice id and an idempotency
 * key, and every number in the response is derived from the release currently
 * loaded by the server. Run pins are recorded provenance; they do not yet select
 * a historical release context.
 *
 * BOARDSIM_DEV=1 swaps the MySQL store for the in-memory one and reports
 * mode=dev on /v1/health. It exists so the front end can be developed without a
 * database, and must never be set where the API is publicly reachable.
 */
$root = dirname(__DIR__, 2);
$bootstrap = $root . '/src/bootstrap.php';

// public/api/index.php knows where its backend is only because the backend lives
// two levels above it. If this file is copied into a fixed Apache document root
// instead of installed through deploy/api-index-shim.php, that assumption breaks
// here — fail loudly and say so, rather than emitting a confusing require error.
if (!is_file($bootstrap)) {
    // The bootstrap is the autoloader, so BoardSim\Http\SecurityHeaders cannot
    // be loaded in this branch: the headers are written out here, and
    // bin/verify-http.php checks this copy against the class constant so the
    // two cannot drift. A 500 an attacker can provoke must not be the one
    // response on this origin without a Content-Security-Policy.
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none'; form-action 'self'; frame-ancestors 'none'; script-src 'self'; style-src 'self'; style-src-attr 'none'; img-src 'self' data:; font-src 'self'; connect-src 'self'");
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cache-Control: no-store');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    echo json_encode([
        'error' => 'ROOT_NOT_FOUND',
        'message' => 'This front controller was copied into the document root without its backend. '
            . 'Install deploy/api-index-shim.php as api/index.php (pointing at the real backend) '
            . 'instead of copying public/api/index.php directly.',
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

require $bootstrap;

use BoardSim\Content\DifficultyViews;
use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;
use BoardSim\Domain\Run\FileRunStore;
use BoardSim\Domain\Run\DomainError;
use BoardSim\Domain\Run\RunService;
use BoardSim\Domain\Run\RunStore;
use BoardSim\Http\ApiRouter;
use BoardSim\Http\ClientAddress;
use BoardSim\Http\RequestBody;
use BoardSim\Http\SecurityHeaders;
use BoardSim\Infrastructure\DatabaseHandle;
use BoardSim\Infrastructure\MysqlRunStore;
use BoardSim\Infrastructure\UnavailableStore;
use BoardSim\Http\MysqlRateLimitStore;
use BoardSim\Http\RateLimiter;

$local = \BoardSim\Infrastructure\EnvFile::bootstrap($root);

$devMode = getenv('BOARDSIM_DEV') === '1';

// The cookie policy is pinned before anything can start a session or set a
// cookie, so no Set-Cookie this origin ever emits can lack HttpOnly, Secure
// or SameSite. The app sets no cookies today; this is the guard that keeps
// that true (and hardens a host-forced session if one ever appears).
\BoardSim\Http\CookiePolicy::harden();

SecurityHeaders::emit(SecurityHeaders::forApi());

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

// Reject oversized, excessively nested or non-object JSON before opening a
// database connection or loading the pinned content bundles. Content-Length is
// only an early hint; RequestBody also reads at most MAX_BYTES + 1 so a missing
// or dishonest header cannot bypass the limit.
try {
    $body = RequestBody::read($_SERVER);
} catch (DomainError $e) {
    http_response_code($e->httpStatus);
    echo json_encode(
        $devMode
            ? ['error' => $e->errorCode, 'message' => $e->getMessage(), 'mode' => 'dev']
            : ['error' => $e->errorCode],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    exit;
}

/**
 * @return array{0: int, 1: mixed}
 */
$route = static function (ApiRouter $router) use ($body, $devMode, $method, $uri): array {
    [$status, $payload] = $router->handle($method, $uri, $body);

    if ($devMode && is_array($payload)) {
        $payload['mode'] = 'dev';
    }

    return [$status, $payload];
};

try {
    // bootstrap() already read config.local.php once: the secrets file (loaded into
    // the environment) wins, and any direct keys a legacy config.local.php returned
    // are the fallback. No second read of the file.
    $config = [
        'dsn' => (string) (getenv('BOARDSIM_DSN') ?: ($local['dsn'] ?? '')),
        'user' => (string) (getenv('BOARDSIM_USER') ?: ($local['user'] ?? '')),
        'pass' => (string) (getenv('BOARDSIM_PASS') ?: ($local['pass'] ?? '')),
    ];

    if ($devMode) {
        // PHP re-executes the script per request, so the store must persist to a
        // file for the SPA to complete a run in development.
        $store = new FileRunStore($root . '/var/dev-runs.json');
        $integrity = ['hash_chain', 'dev_file_store'];
        $shareStore = new \BoardSim\Domain\Share\FileShareStore($root . '/var/dev-shares.json');
    } else {
        // Never fatal on a broken database. `/v1/health` is the deployment's
        // status page on a host with no shell, so it has to answer even when the
        // database does not exist yet; every other route answers 503 from the
        // UnavailableStore rather than a blank 500.
        [$pdo, $databaseError] = DatabaseHandle::connect($config);

        if ($pdo !== null) {
            $store = new MysqlRunStore($pdo);
            $shareStore = new \BoardSim\Infrastructure\MysqlShareStore($pdo);

            // The product has no login, so an unbounded POST is an unbounded
            // write. Enforced here, in the front controller, because this is the
            // only place that sees a real client address. Cloudflare supplies
            // CF-Connecting-IP at the locked origin; REMOTE_ADDR is the local or
            // non-Cloudflare fallback.
            //
            // Fails open on purpose: if the rate-limit table is missing or the
            // counter query errors, the API must still answer. Availability of
            // the exercise matters more than the limiter, and the failure is
            // logged for the operator.
            try {
                $verdict = (new RateLimiter(new MysqlRateLimitStore($pdo)))->check(
                    ClientAddress::fromServer($_SERVER),
                    RateLimiter::actionFor(
                        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                        (string) ($_SERVER['REQUEST_URI'] ?? '/')
                    )
                );

                if ($verdict['allowed'] === false) {
                    http_response_code(429);
                    header('Retry-After: ' . $verdict['retry_after']);
                    header('Content-Type: application/json; charset=utf-8');

                    echo json_encode([
                        'error' => 'RATE_LIMITED',
                        'message' => sprintf(
                            'Too many requests (%s). Retry in %d seconds.',
                            $verdict['action'],
                            $verdict['retry_after']
                        ),
                    ], JSON_UNESCAPED_SLASHES);

                    exit;
                }
            } catch (Throwable $e) {
                error_log('BoardSim rate limiter: ' . $e->getMessage());
            }
        } else {
            $store = new UnavailableStore($databaseError ?? 'unavailable');
            $shareStore = $store;
        }

        $integrity = ['hash_chain']; // add 'db_grants' / 'no_update_trigger' once verified
    }

    $pinned = new PinnedScenarios($root);

    $service = new RunService(
        $store,
        (new OverlayLoader($root))->load(),
        (new ScoringPolicyLoader($root))->load(),
        $pinned->loadAll(),
        $pinned->contentVersion(),
        $pinned->contentSha256(),
        $integrity,
        DifficultyViews::load($root),
        \BoardSim\Content\BoardChallenges::load($root),
        $root,
        null,
        $shareStore
    );

    [$status, $payload] = $route(new ApiRouter($service, $pinned));
} catch (Throwable $e) {
    error_log('BoardSim bootstrap failure: ' . $e->getMessage());
    [$status, $payload] = [500, ['error' => 'BOOTSTRAP_FAILED', 'message' => 'Server is not configured']];
}

if (is_array($payload) && !$devMode) {
    // Never advertise internals in production.
    unset($payload['message']);
    $payload['message'] = $payload['message'] ?? null;
    $payload = array_filter($payload, static fn (mixed $v): bool => $v !== null);
}

http_response_code($status);
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
