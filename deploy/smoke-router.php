<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server, so a BUILT deployment folder can be tested
 * on this machine before it is uploaded.
 *
 *   php -S 127.0.0.1:8200 -t build/deploy/<version>/docroot deploy/smoke-router.php
 *
 * Why it exists: the whole point of bin/build-deploy.php is that the host only
 * ever receives files, so the artefact itself must be proven before it leaves
 * this machine. PHP's built-in server ignores .htaccess, so without this router
 * every /v1 request is answered by PHP's own HTML 404 — the exact failure the
 * shim and the routing rules exist to prevent.
 *
 * It mirrors public/.htaccess: /v1 goes to api/index.php, real files are served,
 * everything else is the SPA fallback, dotfiles (and the backend pointer) are
 * denied.
 *
 * One rule is deliberately NOT mirrored: the Cloudflare origin lock. Real Apache
 * refuses any request without X-Origin-Secret, but reproducing that here would
 * answer 403 to every browser request and defeat the purpose of the pre-upload
 * walkthrough. The built .htaccess still carries the real value; the guard is
 * proven on the live origin by bin/verify-deploy.php, which sends one request
 * without the header and expects 403.
 *
 * Development store: run the server with BOARDSIM_DEV=1 to test an artefact
 * without MySQL. `bin/verify-deploy.php` accepts mode=dev on a loopback origin.
 */

$docroot = rtrim(
    str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? getenv('BOARDSIM_PUBLIC') ?: '')),
    '/'
);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '') {
    $path = '/';
}

// Mirrors ErrorDocument in public/.htaccess: the artefact's own branded pages are
// served with the status they document. A missing page falls back to text so the
// smoke test still reports a real failure instead of an empty 403.
$errorPage = static function (int $status) use ($docroot): bool {
    $page = $docroot . '/errors/' . $status . '.html';

    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');

    if (is_file($page)) {
        readfile($page);
    } else {
        printf("Error %d\n", $status);
    }

    return true;
};

// The backend is found through the pointer this deployment already contains.
// A build always writes it, and the headers must be up before anything
// answers — the API, streamed files, error pages and the redirect below are
// all behind this one emission point. If the pointer or the backend is
// missing, the artefact is broken: fail closed with a mirrored-header 500
// rather than serve headerless pages.
$mirrorHeaders = static function (): void {
    // Mirrors BoardSim\Http\SecurityHeaders::forStatic(); bin/verify-http.php
    // checks this copy against the class constant so it cannot drift.
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none'; form-action 'self'; frame-ancestors 'none'; script-src 'self'; style-src 'self'; style-src-attr 'none'; img-src 'self' data:; font-src 'self'; connect-src 'self'");
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
};

$backendPointer = $docroot . '/api/backend.php';
$backendRoot = is_file($backendPointer)
    ? rtrim(str_replace('\\', '/', (string) require $backendPointer), '/')
    : '';

if ($backendRoot === '' || !is_file($backendRoot . '/src/bootstrap.php')) {
    $mirrorHeaders();

    return $errorPage(500);
}

require_once $backendRoot . '/src/bootstrap.php';

if (!class_exists(\BoardSim\Http\SecurityHeaders::class)) {
    $mirrorHeaders();

    return $errorPage(500);
}

// The cookie policy is pinned before anything answers, so a cookie can never
// leave this origin without HttpOnly, Secure and SameSite.
\BoardSim\Http\CookiePolicy::harden();
\BoardSim\Http\SecurityHeaders::emit(\BoardSim\Http\SecurityHeaders::forStatic());

// Mirrors public/.htaccess: an ACME or DCV validation path is reachable without
// the origin header, because the certificate authority fetches it from the origin
// address rather than through Cloudflare. A missing token is a 404, never the SPA.
if (str_starts_with($path, '/.well-known/') || str_starts_with($path, '/pki-validation/')) {
    $candidate = realpath($docroot . $path);

    if ($candidate === false || !is_file($candidate)) {
        return $errorPage(404);
    }

    return false;
}

// Mirrors the dotfile rule and the explicit denial of the backend pointer.
if (preg_match('#(?:^|/)\.#', $path) === 1 || str_ends_with($path, '/backend.php')) {
    return $errorPage(403);
}

if (str_starts_with($path, '/v1')) {
    require $docroot . '/api/index.php';

    return true;
}

// Mirrors the discovery guard in public/.htaccess: a static file that is not on
// disk is a 404. Answering /robots.txt with the SPA shell and HTTP 200 is how a
// deployment missing the discovery files passed a status-code test while telling
// every crawler the site had no policy.
if (in_array($path, ['/robots.txt', '/sitemap.xml', '/llms.txt'], true)
    && !is_file($docroot . $path)) {
    return $errorPage(404);
}

// Mirrors public/.htaccess: the shell answers at / only, so the address the
// build writes it to redirects rather than serving a second copy.
//
// The body is deliberately empty. ZAP's "Big Redirect Detected" (10044)
// compares a redirect's body against the length of its Location header plus
// 300 bytes; a redirect that carries the page it is leaving — the shell, an
// error page, anything — turns a navigation into a disclosure channel. This
// branch sends the status and the Location and nothing else, and it inherits
// the full security-header set from the emission point above.
if ($path === '/app/index.html') {
    http_response_code(301);
    header('Location: /');

    return true;
}

$candidate = realpath($docroot . $path);
$candidate = $candidate === false ? false : str_replace('\\', '/', $candidate);

if ($path !== '/' && $candidate !== false && str_starts_with($candidate, $docroot) && is_file($candidate)) {
    // Let the built-in server stream the file with the right content type.
    return false;
}

header('Content-Type: text/html; charset=utf-8');
readfile($docroot . '/app/index.html');

return true;
