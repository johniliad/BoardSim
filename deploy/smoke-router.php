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

// Emit the same static-channel headers public/.htaccess sets, so the smoke test
// exercises the real CSP string rather than a copy. The backend is found through
// the pointer this deployment already contains.
$backendPointer = $docroot . '/api/backend.php';

if (is_file($backendPointer)) {
    $backendRoot = rtrim(str_replace('\\', '/', (string) require $backendPointer), '/');

    if (is_file($backendRoot . '/src/bootstrap.php')) {
        require_once $backendRoot . '/src/bootstrap.php';

        if (class_exists(\BoardSim\Http\SecurityHeaders::class)) {
            \BoardSim\Http\SecurityHeaders::emit(
                \BoardSim\Http\SecurityHeaders::forStatic(
                    \BoardSim\Http\SecurityHeaders::requestIsHttps()
                )
            );
        }
    }
}

// Mirrors the dotfile rule and the explicit denial of the backend pointer.
if (preg_match('#(?:^|/)\.#', $path) === 1 || str_ends_with($path, '/backend.php')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";

    return true;
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
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";

    return true;
}

// Mirrors public/.htaccess: the shell answers at / only, so the address the
// build writes it to redirects rather than serving a second copy.
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
