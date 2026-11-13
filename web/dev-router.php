<?php

declare(strict_types=1);

/**
 * Development router for PHP's built-in server, so the SPA and the API can be
 * exercised on one origin exactly as they are in production.
 *
 *   php -S 127.0.0.1:8100 -t public web/dev-router.php
 *
 * The router argument is not optional. PHP's built-in server ignores .htaccess,
 * so without this script /v1/... is answered by PHP's own HTML 404 page: the SPA
 * then renders with no data and the footer reports the scoring policy as
 * unavailable.
 *
 * Not used in production: Apache handles this through public/.htaccess, and this
 * script mirrors it — the /v1 route, the static SPA fallback, dotfile denial and
 * the same security headers, taken from BoardSim\Http\SecurityHeaders rather than
 * copied, so a CSP regression shows up in development as well as after deployment.
 */
require_once __DIR__ . '/../src/bootstrap.php';

use BoardSim\Http\SecurityHeaders;

$documentRoot = realpath(__DIR__ . '/../public');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Mirrors public/.htaccess: a dotfile is never served.
if (preg_match('#(?:^|/)\.#', $path) === 1) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";

    return true;
}

SecurityHeaders::emit(SecurityHeaders::forStatic(SecurityHeaders::requestIsHttps()));

if (str_starts_with($path, '/v1')) {
    require __DIR__ . '/../public/api/index.php';

    return true;
}

$candidate = $documentRoot === false || $documentRoot === '' ? false : realpath($documentRoot . $path);
$inside = $candidate !== false && str_starts_with($candidate, $documentRoot . DIRECTORY_SEPARATOR);

if ($path !== '/' && $inside && is_file($candidate)) {
    // Let the built-in server stream the file with the right content type.
    return false;
}

$index = __DIR__ . '/../public/app/index.html';

if (!is_file($index)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "The SPA has not been built yet. Run: cd web && npm install && npm run build\n";

    return true;
}

header('Content-Type: text/html; charset=utf-8');
readfile($index);

return true;
