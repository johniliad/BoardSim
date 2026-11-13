<?php

declare(strict_types=1);

/**
 * Local development server launcher.
 *
 *   php bin/dev.php                # http://127.0.0.1:8100
 *   php bin/dev.php --port=8200    # another port (Vite then needs the same one)
 *
 * Two things must both be true for the SPA to work locally, and both are easy to
 * get wrong by hand:
 *
 *   1. web/dev-router.php must be passed to PHP's built-in server, because that
 *      server ignores .htaccess. Without it, every /v1/... call is answered by
 *      PHP's own HTML 404 page: the SPA renders, the footer reports the scoring
 *      policy as unavailable, and the console shows "Unexpected token '<'".
 *   2. BOARDSIM_DEV=1 must be set, because there is no MySQL locally.
 *
 * This launcher sets both. It is a development convenience only: never present
 * on a public host, where Apache serves public/.htaccess instead.
 */
$root = dirname(__DIR__);
$port = '8100';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--port=')) {
        $port = substr($arg, strlen('--port='));
    }
}

if (preg_match('/^\d{2,5}$/', $port) !== 1) {
    fwrite(STDERR, "Invalid --port: {$port}\n");
    exit(2);
}

if (!is_file($root . '/public/app/index.html')) {
    fwrite(STDERR, "The SPA has not been built yet. Run: cd web && npm install && npm run build\n");
    exit(1);
}

$router = $root . '/web/dev-router.php';

printf("BoardSim development server\n");
printf("  url:    http://127.0.0.1:%s\n", $port);
printf("  router: web/dev-router.php (required: PHP's server ignores .htaccess)\n");
printf("  store:  file var/dev-runs.json, mode=dev\n");
printf("  hot reload: npm run dev in web/ proxies /v1 to 127.0.0.1:8100\n\n");

$environment = getenv();
$environment['BOARDSIM_DEV'] = '1';

$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $router],
    [STDIN, STDOUT, STDERR],
    $pipes,
    $root,
    $environment
);

if (!is_resource($process)) {
    fwrite(STDERR, "Could not start the PHP development server.\n");
    exit(1);
}

exit(proc_close($process));
