<?php

declare(strict_types=1);

/**
 * Deployment target for bin/build-deploy.php — TEMPLATE.
 *
 * Copy this file to deploy/host.config.php (gitignored) and fill it in. Nothing
 * in this repository may contain a real host path; only this file and the
 * artefacts build-deploy writes into dist/ ever do.
 *
 * The builder refuses to run while a placeholder is still present, so a build
 * made from the template cannot be uploaded by accident.
 *
 * layout = 'split'  The document root is separate from the backend (the common
 *                   cPanel case: the subdomain has its own folder and the code
 *                   lives beside public_html). A generated api/backend.php
 *                   pointer bridges the two.
 *
 * layout = 'flat'   The document root IS <backend>/public. No pointer at all.
 *                   Use this if the host's open_basedir refuses to read outside
 *                   the document root: change the subdomain's DocumentRoot to
 *                   <backend>/public in cPanel and rebuild.
 */
return [
    // 'split' or 'flat'
    'layout' => 'split',

    // cPanel account username. Only used to build and print paths.
    'account' => 'USER',

    // Public origin, without a trailing slash. Used by cron.txt and verify.txt.
    'domain' => 'boardsim.example.net',

    // Absolute path of the directory Apache serves (split), or
    // <backend>/public (flat).
    'docroot' => '/home/USER/public_html/boardsim.example.net',

    // Absolute path of the private directory that holds src/, content/, bin/…
    // MUST NOT be inside the document root.
    'backend' => '/home/USER/boardsim-app',

    // null  → credentials live directly in <backend>/config.local.php (one file
    //         to create on the host, the simplest option without shell access).
    // path  → config.local.php points at that file instead (keeps the secret in
    //         its own 0600 file, e.g. /home/USER/boardsim-secrets/boardsim.env).
    'secrets_file' => null,

    // The value of the X-Origin-Secret header that Cloudflare's Transform Rule
    // sets on every request. public/.htaccess is committed with the placeholder
    // __CF_ORIGIN_SECRET__; this value is substituted into the built .htaccess,
    // which is how the origin refuses traffic that did not come through
    // Cloudflare without the secret ever reaching GitHub. Leave it empty and the
    // build refuses to run. See requirements/12_CLOUDFLARE_ORIGIN_LOCK.md.
    //
    // 32-128 characters of A-Z a-z 0-9 _ or - (no regex metacharacters, because
    // the value is compared inside a RewriteCond pattern). Generate one with:
    //   php -r "echo bin2hex(random_bytes(32));"
    'cloudflare_origin_secret' => '',

    // The database and user already created in cPanel → MySQL Databases.
    // cPanel prefixes both with the account username.
    'db' => [
        'name' => 'USER_boardsim',
        'user' => 'USER_boardsim',
    ],

    // Absolute path of the PHP CLI binary cron should use. It must be the same
    // PHP version the domain runs, or cron parses the code with a different
    // interpreter than the web handler does.
    'php_binary' => '/usr/local/bin/php',

    // Cron schedules written into dist/cron.txt.
    'cron' => [
        'migrate' => '*/10 * * * *',   // install day; switch to '0 2 * * *' afterwards
        'purge' => '15 3 * * *',
        'verify' => '30 3 * * *',
    ],
];
