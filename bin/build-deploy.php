<?php

declare(strict_types=1);

/**
 * Build the two directories a shell-less cPanel host needs.
 *
 *   cp deploy/host.config.example.php deploy/host.config.php   # once
 *   php bin/build-deploy.php --version=1.2.0
 *
 * Output (all under build/deploy/<version>/):
 *
 *   docroot/     upload into the subdomain's DocumentRoot
 *   backend/     upload beside public_html, OUTSIDE the document root
 *   install.sql  optional one-shot schema for phpMyAdmin
 *   cron.txt     the cron entries to paste
 *   verify.txt   the post-upload check to run from this machine
 *   README.txt   the six upload steps with these paths filled in
 *
 * Nothing here uploads anything, and nothing here runs on the host: the target
 * has no shell, so the artefact is assembled where PHP and npm are available and
 * the host only ever receives files.
 *
 * The build refuses to run if the specification has drifted (manifest, content
 * bundle), if the SPA has not been built, if the host config still contains a
 * placeholder, or if the Cloudflare origin secret is missing — so a template
 * build cannot reach a real server.
 */

require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\BundleSeeder;
use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;

/**
 * The placeholder in public/.htaccess that the build fills in.
 *
 * The committed .htaccess carries this instead of the Cloudflare origin secret,
 * because this repository is public. deploy/host.config.php holds the value and a
 * build without one is refused, so the guard can never ship half-configured.
 */
const ORIGIN_SECRET_PLACEHOLDER = '__CF_ORIGIN_SECRET__';

$root = dirname(__DIR__);

$options = [
    'config' => $root . '/deploy/host.config.php',
    'out' => $root . '/build/deploy',
    'version' => 'v' . gmdate('Y.m.d'),
    'zip' => true,
    'checks' => true,
    'overrides' => [],
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--no-zip') {
        $options['zip'] = false;
        continue;
    }

    if ($argument === '--skip-checks') {
        $options['checks'] = false;
        continue;
    }

    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match) !== 1) {
        fail(["Unknown argument: {$argument}"]);
    }

    $key = $match[1];
    $value = $match[2];

    if (in_array($key, ['config', 'out', 'version'], true)) {
        $options[$key] = $value;
        continue;
    }

    if (in_array($key, ['layout', 'account', 'domain', 'docroot', 'backend', 'php-binary'], true)) {
        $options['overrides'][str_replace('-', '_', $key)] = $value;
        continue;
    }

    fail(["Unknown option: --{$key}"]);
}

if (!is_file($options['config'])) {
    fail([
        "Missing {$options['config']}.",
        'Copy deploy/host.config.example.php to deploy/host.config.php and fill it in.',
    ]);
}

$config = require $options['config'];

if (!is_array($config)) {
    fail(["{$options['config']} must return an array."]);
}

$config = array_replace($config, $options['overrides']);

$config['layout'] = (string) ($config['layout'] ?? 'split');
$config['account'] = (string) ($config['account'] ?? '');
$config['domain'] = rtrim((string) ($config['domain'] ?? ''), '/');
$config['docroot'] = normalisePath((string) ($config['docroot'] ?? ''));
$config['backend'] = normalisePath((string) ($config['backend'] ?? ''));
$config['secrets_file'] = $config['secrets_file'] ?? null;
$config['cloudflare_origin_secret'] = (string) ($config['cloudflare_origin_secret'] ?? '');
$config['db'] = [
    'name' => (string) ($config['db']['name'] ?? ''),
    'user' => (string) ($config['db']['user'] ?? ''),
];
$config['php_binary'] = (string) ($config['php_binary'] ?? '/usr/local/bin/php');
$config['cron'] = array_replace(
    ['migrate' => '0 2 * * *', 'purge' => '15 3 * * *', 'verify' => '30 3 * * *'],
    is_array($config['cron'] ?? null) ? $config['cron'] : []
);

$problems = validateConfig($config);

if ($problems !== []) {
    fail($problems);
}

if (!is_file($root . '/public/app/index.html')) {
    fail([
        'The SPA has not been built: public/app/index.html is missing.',
        'Run: cd web && npm ci && npm run build',
    ]);
}

if ($options['checks']) {
    $checks = [
        'php bin/verify-manifest.php',
        'php bin/content-build.php --check',
    ];

    foreach ($checks as $check) {
        $output = [];
        $status = 0;
        exec('cd ' . escapeshellarg($root) . ' && ' . $check . ' 2>&1', $output, $status);

        if ($status !== 0) {
            fail([
                "Pre-flight check failed: {$check}",
                implode("\n", array_slice($output, -8)),
                'Fix it, or pass --skip-checks if you know why it fails.',
            ]);
        }
    }
}

$version = $options['version'];
$outRoot = normalisePath($options['out']) . '/boardsim-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $version);
$docrootOut = $outRoot . '/docroot';
$backendOut = $outRoot . '/backend';

removeTree($outRoot);

// ── Backend: everything that must not be web-addressable ─────────────────────
foreach (['src', 'content', 'engine', 'requirements', 'scenarios', 'migrations'] as $directory) {
    copyTree($root . '/' . $directory, $backendOut . '/' . $directory);
}

copyFile($root . '/public/api/index.php', $backendOut . '/public/api/index.php');
copyFile($root . '/deploy/backend.htaccess', $backendOut . '/.htaccess');

// The backend tree is the source form of the application, so it carries its own
// licence and attributions: whoever receives these files receives the terms that
// cover them, which is what Apache-2.0 §4(a) and the MIT notice ask for. The
// browser-facing copy is public/licences.txt.
foreach (['LICENSE', 'THIRD-PARTY-NOTICES.md'] as $notice) {
    copyFile($root . '/' . $notice, $backendOut . '/' . $notice);
}

foreach (['migrate.php', 'purge.php', 'verify-deploy.php'] as $script) {
    copyFile($root . '/bin/' . $script, $backendOut . '/bin/' . $script);
}

// ── Document root: the SPA, the routing rules and one shim ───────────────────
copyTree($root . '/public/app', $docrootOut . '/app');

// Error documents: static HTML in the site's own design, pointed at by
// ErrorDocument in public/.htaccess. They are separate files rather than the SPA
// because Apache answers 403/404/500 before the app exists — a missing asset or a
// refused dotfile must still look like the product, and must still render when
// PHP is the thing that failed.
copyTree($root . '/public/errors', $docrootOut . '/errors');

copyFile($root . '/public/.htaccess', $docrootOut . '/.htaccess');
copyFile($root . '/deploy/api-index-shim.php', $docrootOut . '/api/index.php');

// The zero-body redirect for the duplicate shell address. Apache's own 301
// page carries a body that sits on ZAP's "Big Redirect Detected" line (10044:
// a body larger than the Location header plus 300 bytes); this file answers
// with the status, the Location and nothing else.
copyFile($root . '/public/redirect-to-root.php', $docrootOut . '/redirect-to-root.php');

// The licence obligation, served rather than shipped: the origin answers
// /licences.txt with the attributions that apply to the bytes a browser downloads.
copyFile($root . '/public/licences.txt', $docrootOut . '/licences.txt');

// ── The Cloudflare origin lock: the one value the build injects ──────────────
// public/.htaccess ships with a placeholder, so the secret exists only in
// deploy/host.config.php (gitignored) and in this artefact. Both directions of a
// mistake are silent without this check: a placeholder left in place makes
// Apache refuse every request including Cloudflare's, and a missing rule lets the
// origin answer anyone who resolves its address.
$guardSource = (string) file_get_contents($docrootOut . '/.htaccess');

if (!str_contains($guardSource, ORIGIN_SECRET_PLACEHOLDER)) {
    fail([
        'public/.htaccess has no ' . ORIGIN_SECRET_PLACEHOLDER . ' placeholder.',
        'The Cloudflare origin lock is always on: restore the RewriteCond on',
        'X-Origin-Secret in public/.htaccess (requirements/12_CLOUDFLARE_ORIGIN_LOCK.md).',
    ]);
}

writeFile(
    $docrootOut . '/.htaccess',
    str_replace(ORIGIN_SECRET_PLACEHOLDER, $config['cloudflare_origin_secret'], $guardSource, $replaced)
);

$guard = (string) file_get_contents($docrootOut . '/.htaccess');

// Exactly one substitution: the RewriteCond. A second occurrence would mean the
// value has leaked into a comment, and none would mean the rule is gone.
if ($replaced !== 1
    || str_contains($guard, ORIGIN_SECRET_PLACEHOLDER)
    || !str_contains($guard, 'RewriteCond %{HTTP:X-Origin-Secret} !^' . $config['cloudflare_origin_secret'] . '$')) {
    fail([
        'The Cloudflare origin lock did not survive substitution in docroot/.htaccess '
            . "({$replaced} replacement(s), expected 1).",
    ]);
}

// The cron-run verification on the host reaches the origin directly, so it has to
// present the same header. Writing the value beside the code (outside the
// document root) keeps it out of cron.txt, where a command line is visible to
// every process on a shared host.
writeFile($backendOut . '/cloudflare-origin.secret', $config['cloudflare_origin_secret'] . "\n");

// Discovery files: served as plain static files from the document root, so a
// crawler, a social-preview bot or a language model reads them without running
// the SPA. They sit next to the SPA rather than inside app/ because robots.txt
// and sitemap.xml are only valid at the origin root.
foreach (['robots.txt', 'sitemap.xml', 'llms.txt'] as $discovery) {
    copyFile($root . '/public/' . $discovery, $docrootOut . '/' . $discovery);
}

$sitemap = (string) file_get_contents($docrootOut . '/sitemap.xml');

if (!str_contains($sitemap, '__LASTMOD__')) {
    fail([
        'public/sitemap.xml has no __LASTMOD__ placeholder.',
        'The build stamps it with the assembly date; without the placeholder the',
        'sitemap would silently keep a stale date. Restore the placeholder.',
    ]);
}

writeFile($docrootOut . '/sitemap.xml', str_replace('__LASTMOD__', gmdate('Y-m-d'), $sitemap));

if ($config['layout'] === 'split') {
    writeFile(
        $docrootOut . '/api/backend.php',
        "<?php\n\n// Generated by bin/build-deploy.php. The backend directory, outside this\n"
        . "// document root. Holds no secret: only a filesystem path.\n"
        . "declare(strict_types=1);\n\n"
        . 'return ' . var_export($config['backend'], true) . ";\n"
    );
}

// ── Config template: the one file the operator edits on the host ─────────────
if (is_string($config['secrets_file']) && $config['secrets_file'] !== '') {
    $componentConfig = "<?php\n\n// Generated by bin/build-deploy.php. Holds no secret: it is a pointer to the\n"
        . "// file that does (mode 0600, outside every document root).\n"
        . "declare(strict_types=1);\n\nreturn [\n    'secrets_file' => "
        . var_export($config['secrets_file'], true) . ",\n];\n";
} else {
    $componentConfig = "<?php\n\n// Generated by bin/build-deploy.php. Copy this file to config.local.php in the\n"
        . "// backend directory and fill in the password. It lives outside the document\n"
        . "// root, so a misconfigured server cannot serve it.\n"
        . "declare(strict_types=1);\n\nreturn [\n"
        . "    'dsn'  => " . var_export('mysql:host=localhost;dbname=' . $config['db']['name'] . ';charset=utf8mb4', true) . ",\n"
        . "    'user' => " . var_export($config['db']['user'], true) . ",\n"
        . "    'pass' => 'CHANGE-ME',\n];\n";
}

writeFile($backendOut . '/config.local.php.example', $componentConfig);

// ── Pinned artefacts, read once for the release stamp and the SQL seed ───────
$policy = (new ScoringPolicyLoader($root))->load();
$regulatory = (new OverlayLoader($root))->load();
$pinned = new PinnedScenarios($root);

$bundleRows = BundleSeeder::rows(
    $policy,
    $regulatory,
    $pinned->contentVersion(),
    $pinned->contentSha256()
);

$release = [
    'version' => $version,
    'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'commit' => gitCommit($root),
    'layout' => $config['layout'],
    'account' => $config['account'],
    'domain' => $config['domain'],
    'content_version' => $pinned->contentVersion(),
    'ruleset_version' => (string) $policy['ruleset_version'],
    'tree_sha256' => treeHash($backendOut, ['release.json']),
];

writeFile(
    $backendOut . '/release.json',
    json_encode($release, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

// ── install.sql: the optional one-shot schema for phpMyAdmin ─────────────────
$sql = "-- BoardSim — schema and pinned bundles\n"
    . "-- Generated by bin/build-deploy.php from migrations/*.sql and the pinned\n"
    . "-- content overlays. Paste this whole file into phpMyAdmin → SQL → Go.\n"
    . "--\n"
    . "-- Optional: bin/migrate.php (cron) produces exactly the same state and is\n"
    . "-- idempotent. Use this file when you do not want to wait for a cron tick.\n\n";

foreach (glob($root . '/migrations/*.sql') ?: [] as $migration) {
    $sql .= '-- ── ' . basename($migration) . " ──\n"
        . trim((string) file_get_contents($migration)) . "\n\n";
}

$sql .= "-- The three pinned bundles. They must exist before the first run, because\n"
    . "-- runs.policy_sha256 has a foreign key to content_bundles.bundle_sha256.\n\n";

foreach ($bundleRows as $row) {
    $sql .= "INSERT IGNORE INTO content_bundles (bundle_sha256, bundle_kind, version, canonical_payload)\n"
        . "VALUES ('" . $row['sha'] . "', '" . $row['kind'] . "', '" . $row['version'] . "', '"
        . str_replace("'", "''", $row['payload']) . "');\n\n";
}

writeFile($outRoot . '/install.sql', $sql);

// ── cron.txt and verify.txt: the exact lines to paste ────────────────────────
$logDir = $config['backend'];

$cron = "# BoardSim cron entries — paste into cPanel → Cron Jobs.\n"
    . "# Use the same PHP version the domain runs (see php_binary above), or cron\n"
    . "# will parse the code with a different interpreter than the web handler.\n\n"
    . $config['cron']['migrate'] . '  ' . $config['php_binary'] . ' ' . $logDir . '/bin/migrate.php'
    . '  >> ' . $logDir . '/migrate.log 2>&1' . "\n"
    . $config['cron']['purge'] . '  ' . $config['php_binary'] . ' ' . $logDir . '/bin/purge.php'
    . '  >> ' . $logDir . '/purge.log 2>&1' . "\n"
    // The origin answers only what Cloudflare forwarded, and this job runs on the
    // host, so it goes straight to the origin and must present the header itself.
    // The value is in a file rather than on this command line, which any process
    // on a shared host can read.
    . $config['cron']['verify'] . ' ' . $config['php_binary'] . ' ' . $logDir . '/bin/verify-deploy.php'
    . ' --base=' . $config['domain'] . ' --require-ready'
    . ' --origin-secret-file=' . $config['backend'] . '/cloudflare-origin.secret'
    . '  >> ' . $logDir . '/verify.log 2>&1' . "\n\n"
    . "# After the first successful deploy, change the migrate line to '0 2 * * *'.\n"
    . "# GET_LOCK('boardsim_migrate') makes overlapping runs no-op, so a tighter\n"
    . "# schedule is safe, only noisier.\n";

writeFile($outRoot . '/cron.txt', $cron);

$verify = "# Run from the repository root on this machine, after uploading.\n"
    . "# --config supplies the origin AND the Cloudflare origin secret, so the\n"
    . "# request is not refused with 403 by the lock in .htaccess.\n\n"
    . 'php bin/verify-deploy.php --config=' . str_replace('\\', '/', $options['config'])
    . ' --require-ready --expect-release=' . $release['tree_sha256'] . "\n\n"
    . "# Or name the origin explicitly. Point --origin-secret-file at any file holding\n"
    . "# the cloudflare_origin_secret from " . str_replace('\\', '/', $options['config']) . ":\n"
    . '# php bin/verify-deploy.php --base=' . $config['domain']
    . " --origin-secret-file=<file> --require-ready\n";

writeFile($outRoot . '/verify.txt', $verify);

// ── README.txt: the copy-paste deploy procedure for this target ──────────────
$readme = "BoardSim {$version} — deploy by copying files\n"
    . str_repeat('=', 60) . "\n\n"
    . "Document root : {$config['docroot']}\n"
    . "Backend       : {$config['backend']}\n"
    . "Database      : {$config['db']['name']} as {$config['db']['user']}\n"
    . "Release hash  : {$release['tree_sha256']}\n\n"
    . "1. cPanel → MySQL Databases: create {$config['db']['name']} and user\n"
    . "   {$config['db']['user']}, attach with privileges.\n"
    . "2. File Manager: upload backend.zip and extract it into the parent of\n"
    . "   {$config['backend']} so the folder is created there.\n"
    . "3. In the backend, copy config.local.php.example to config.local.php and\n"
    . "   fill in the password.\n"
    . "4. File Manager: upload docroot-{$version}.zip (or docroot.zip) and extract it\n"
    . "   into {$config['docroot']}. It must end up holding .htaccess, app/, api/,\n"
    . "   errors/, robots.txt, sitemap.xml, llms.txt and licences.txt — all of them at\n"
    . "   that level, none inside app/: robots.txt and sitemap.xml are only honoured\n"
    . "   at the origin root, and /errors/ is what ErrorDocument points at.\n"
    . "   The .htaccess carries the Cloudflare origin lock: every request without\n"
    . "   the X-Origin-Secret header answers 403. Add the Cloudflare Transform Rule\n"
    . "   that sets it BEFORE this upload, or the site is locked out until you do.\n"
    . "5. Optional, for an instant schema: phpMyAdmin → SQL → paste install.sql.\n"
    . "6. cPanel → Cron Jobs: paste the lines from cron.txt.\n"
    . "7. Open https://{$config['domain']}/v1/health → status ok.\n"
    . "8. On this machine: see verify.txt.\n\n"
    . "Every later deploy is steps 2, 4 and 8. config.local.php and the database\n"
    . "are never touched by an upload.\n\n"
    . "cloudflare-origin.secret (backend, mode 0600) holds the value cron presents to\n"
    . "the locked origin. Rotating it means updating the Cloudflare rule and\n"
    . "re-uploading .htaccess in the same window.\n";

writeFile($outRoot . '/README.txt', $readme);

// ── Zips: the two artefacts to upload ────────────────────────────────────────
$zips = [];

if ($options['zip']) {
    try {
        $zips['docroot.zip'] = zipTree($docrootOut, $outRoot . '/docroot.zip');
        $zips['backend.zip'] = zipTree($backendOut, $outRoot . '/backend.zip');
    } catch (Throwable $e) {
        fail(['Cannot create the zip archives: ' . $e->getMessage()]);
    }

    // The archive is what gets uploaded, so the build reads it back instead of
    // trusting the directory it came from. A docroot zip without the discovery
    // files has already shipped once: the SPA fallback then answers /robots.txt
    // with HTML, Google sees the homepage as 403, and every artefact-level test
    // still passes because the directory on this machine is complete.
    $required = [
        'docroot.zip' => [$docrootOut, [
            '.htaccess',
            'robots.txt',
            'sitemap.xml',
            'llms.txt',
            'licences.txt',
            'app/index.html',
            'api/index.php',
            'redirect-to-root.php',
            'errors/error.css',
            'errors/400.html',
            'errors/403.html',
            'errors/404.html',
            'errors/500.html',
            'errors/503.html',
        ]],
        'backend.zip' => [$backendOut, [
            '.htaccess',
            'LICENSE',
            'THIRD-PARTY-NOTICES.md',
            'src/bootstrap.php',
            'bin/migrate.php',
            'cloudflare-origin.secret',
        ]],
    ];

    if ($config['layout'] === 'split') {
        $required['docroot.zip'][1][] = 'api/backend.php';
    }

    foreach ($required as $name => [$tree, $entries]) {
        $archive = $outRoot . '/' . $name;

        try {
            $names = zipNames($archive);
        } catch (Throwable $e) {
            fail([$e->getMessage(), 'The archive cannot be verified, so it must not be uploaded.']);
        }

        $problems = [];

        foreach ($entries as $entry) {
            if (!is_file($tree . '/' . $entry) || filesize($tree . '/' . $entry) === 0) {
                $problems[] = "{$entry} is missing or empty in {$tree}";
            }

            if (!in_array($entry, $names, true)) {
                $problems[] = "{$name} does not contain {$entry}";
            }
        }

        if ($problems !== []) {
            // Never leave an unverified archive on disk: it is exactly the file
            // that gets uploaded at midnight, and it looks complete.
            foreach (['docroot.zip', 'backend.zip'] as $unverified) {
                @unlink($outRoot . '/' . $unverified);
            }

            fail(array_merge($problems, [
                'The archive is the artefact; a complete directory is not enough.',
            ]));
        }

        // Same bytes, second name: a file list cannot tell 1.2.0 from 1.2.2 when
        // only PHP and HTML changed, and neither can the operator at 01:00.
        $versioned = $outRoot . '/' . preg_replace('/\.zip$/', '', $name)
            . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $version) . '.zip';

        if (!copy($archive, $versioned)) {
            fail(["Cannot write {$versioned}"]);
        }

        $zips[$name] .= '  +  ' . basename($versioned);
    }
}

// ── Summary ─────────────────────────────────────────────────────────────────
printf("Built %s\n\n", $outRoot);
printf("  docroot/   %5d file(s)  -> upload into %s\n", countFiles($docrootOut), $config['docroot']);
printf("  backend/   %5d file(s)  -> upload beside public_html as %s\n", countFiles($backendOut), $config['backend']);
printf("  install.sql              -> optional: paste into phpMyAdmin\n");
printf("  cron.txt                 -> paste into cPanel → Cron Jobs\n");
printf("  verify.txt               -> run from this machine after upload\n");
printf("  README.txt               -> the same steps, with these paths\n\n");

printf("  release  %s  (%s, %s)\n", $version, $pinned->contentVersion(), (string) $policy['ruleset_version']);
printf("  tree     %s\n", $release['tree_sha256']);
printf("  pointer  %s\n", $config['layout'] === 'split'
    ? $docrootOut . '/api/backend.php -> ' . $config['backend']
    : 'none (docroot is the backend public/ directory)');
printf(
    "  origin   X-Origin-Secret lock armed (%d chars, not printed)\n\n",
    strlen($config['cloudflare_origin_secret'])
);

foreach ($zips as $name => $method) {
    printf("  %-12s %s\n", $name, $method);
}

printf("\nNext: upload both zips, then run the line in %s\n", $outRoot . '/verify.txt');

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * @param list<string> $problems
 */
function fail(array $problems): void
{
    fwrite(STDERR, "build-deploy: cannot build the deployment\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, '  - ' . $problem . "\n");
    }

    exit(2);
}

function normalisePath(string $path): string
{
    return rtrim(str_replace('\\', '/', trim($path)), '/');
}

/**
 * @return list<string>
 */
function validateConfig(array $config): array
{
    $problems = [];

    foreach (['docroot', 'backend'] as $key) {
        if ($config[$key] === '') {
            $problems[] = "{$key} is empty.";
        } elseif (preg_match('#^(/|[A-Za-z]:/)#', $config[$key]) !== 1) {
            $problems[] = "{$key} must be an absolute path, got: {$config[$key]}";
        }
    }

    if ($config['domain'] === '') {
        $problems[] = 'domain is empty (used for the cron and verify lines).';
    }

    // A template build must never reach a server.
    foreach (['docroot', 'backend'] as $key) {
        foreach (['/home/USER', 'YOURUSER'] as $placeholder) {
            if (str_contains($config[$key], $placeholder)) {
                $problems[] = "{$key} still contains the placeholder {$placeholder}.";
            }
        }
    }

    if ($config['account'] === 'USER') {
        $problems[] = 'account is still the placeholder USER.';
    }

    if (str_contains($config['domain'], 'example')) {
        $problems[] = "domain is still a placeholder: {$config['domain']}";
    }

    // The Cloudflare origin lock is always on (requirements/12_CLOUDFLARE_ORIGIN_LOCK.md),
    // so a build without a usable secret has nowhere to go: baking the placeholder
    // into .htaccess would make Apache refuse every request, Cloudflare's included,
    // and dropping the rule would leave the origin open with nothing failing. The
    // character set is narrow on purpose, so the value needs no escaping inside the
    // RewriteCond pattern.
    $originSecret = $config['cloudflare_origin_secret'];

    if ($originSecret === '') {
        $problems[] = 'cloudflare_origin_secret is empty: set it in deploy/host.config.php';
    } elseif (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $originSecret) !== 1) {
        $problems[] = 'cloudflare_origin_secret must be 32-128 characters of A-Z a-z 0-9 _ or -'
            . ' (no regex metacharacters). Generate one with: php -r "echo bin2hex(random_bytes(32));"';
    } elseif (preg_match('/(?:CHANGEME|YOUR|EXAMPLE)/i', $originSecret) === 1) {
        $problems[] = 'cloudflare_origin_secret still looks like a placeholder.';
    }

    if (str_contains($config['db']['name'], 'USER_') || str_contains($config['db']['user'], 'USER_')) {
        $problems[] = 'db.name / db.user still contain the USER_ placeholder.';
    }

    if (!in_array($config['layout'], ['split', 'flat'], true)) {
        $problems[] = "layout must be 'split' or 'flat', got: {$config['layout']}";
    }

    if ($config['layout'] === 'split') {
        if (isInside($config['backend'], $config['docroot'])) {
            $problems[] = 'backend must NOT be inside the document root: '
                . "{$config['backend']} is under {$config['docroot']}.";
        }

        if (isInside($config['docroot'], $config['backend'])) {
            $problems[] = 'docroot must NOT be inside the backend: '
                . "{$config['docroot']} is under {$config['backend']}.";
        }
    } elseif ($config['docroot'] !== $config['backend'] . '/public') {
        $problems[] = 'flat layout requires docroot = backend/public, got '
            . $config['docroot'] . ' vs ' . $config['backend'] . '/public';
    }

    return $problems;
}

function isInside(string $child, string $parent): bool
{
    return $child === $parent || str_starts_with($child . '/', $parent . '/');
}

function copyTree(string $from, string $to): void
{
    if (!is_dir($to) && !mkdir($to, 0o755, true) && !is_dir($to)) {
        fail(["Cannot create {$to}"]);
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $target = $to . '/' . $items->getSubPathname();

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0o755, true) && !is_dir($target)) {
                fail(["Cannot create {$target}"]);
            }

            continue;
        }

        copyFile($item->getPathname(), $target);
    }
}

function copyFile(string $from, string $to): void
{
    $directory = dirname($to);

    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
        fail(["Cannot create {$directory}"]);
    }

    if (!is_file($from)) {
        fail(["Missing source file: {$from}"]);
    }

    if (!copy($from, $to)) {
        fail(["Cannot copy {$from} to {$to}"]);
    }
}

function writeFile(string $path, string $contents): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
        fail(["Cannot create {$directory}"]);
    }

    if (file_put_contents($path, $contents) === false) {
        fail(["Cannot write {$path}"]);
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($path);
}

function countFiles(string $directory): int
{
    $count = 0;

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($items as $item) {
        if ($item instanceof SplFileInfo && $item->isFile()) {
            $count++;
        }
    }

    return $count;
}

/**
 * Content hash of the backend tree: the value /v1/health reports and
 * `verify-deploy --expect-release` asserts.
 *
 * @param list<string> $exclude relative paths to leave out
 */
function treeHash(string $directory, array $exclude = []): string
{
    $base = rtrim(str_replace('\\', '/', $directory), '/');
    $lines = [];

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if (!$item->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $item->getPathname());
        $relative = ltrim(substr($path, strlen($base)), '/');

        if (in_array($relative, $exclude, true)) {
            continue;
        }

        $lines[$relative] = (string) hash_file('sha256', $path);
    }

    ksort($lines);
    $buffer = '';

    foreach ($lines as $relative => $hash) {
        $buffer .= $relative . "\0" . $hash . "\n";
    }

    return hash('sha256', $buffer);
}

function gitCommit(string $root): ?string
{
    $output = [];
    $status = 0;

    exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>&1', $output, $status);
    $commit = trim(implode('', $output));

    return $status === 0 && $commit !== '' ? $commit : null;
}

/**
 * Zip a directory without assuming which tool the machine has.
 *
 * ZipArchive is absent from some Windows PHP builds, PowerShell is not on Linux,
 * and the host cannot unzip anything itself — so try in order and report which
 * one was used. Hidden files are included: .htaccess is part of the artefact.
 */
function zipTree(string $directory, string $zipPath): string
{
    $directory = rtrim(str_replace('\\', '/', $directory), '/');

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($items as $item) {
                /** @var SplFileInfo $item */
                if (!$item->isFile()) {
                    continue;
                }

                $path = str_replace('\\', '/', $item->getPathname());
                $zip->addFile($path, ltrim(substr($path, strlen($directory)), '/'));
            }

            $zip->close();

            return 'ZipArchive';
        }
    }

    $output = [];
    $status = 0;

    if (DIRECTORY_SEPARATOR === '\\') {
        // bsdtar ships with Windows 10+ and writes a real zip with -a.
        $command = 'tar -a -cf ' . escapeshellarg($zipPath) . ' -C ' . escapeshellarg($directory) . ' .';
        $label = 'tar (bsdtar)';
    } else {
        $command = 'cd ' . escapeshellarg($directory) . ' && zip -qr ' . escapeshellarg($zipPath) . ' .';
        $label = 'zip';
    }

    exec($command . ' 2>&1', $output, $status);

    if ($status !== 0 || !is_file($zipPath) || filesize($zipPath) === 0) {
        throw new RuntimeException(
            'no usable zip tool (' . $label . '): ' . trim(implode(' ', $output))
        );
    }

    return $label;
}

/**
 * The file names inside a zip archive, read back from the archive itself.
 *
 * ZipArchive when the extension is loaded (`composer check` and CI have it);
 * otherwise `tar -tf`, because bsdtar reads zip archives and ships with Windows
 * 10+. Names only: sizes would have to be parsed out of a tar listing whose field
 * layout differs between GNU and BSD tar, and content is already proven in the
 * tree this archive was just built from. A listing that cannot be read is a hard
 * failure — an unverifiable artefact is not an artefact.
 *
 * @return list<string>
 */
function zipNames(string $zipPath): array
{
    if (!is_file($zipPath)) {
        throw new RuntimeException("No archive at {$zipPath}");
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open {$zipPath}");
        }

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat !== false) {
                $names[] = zipEntryName((string) $stat['name']);
            }
        }

        $zip->close();

        return $names;
    }

    $output = [];
    $status = 0;

    exec('tar -tf ' . escapeshellarg($zipPath) . ' 2>&1', $output, $status);

    if ($status !== 0) {
        throw new RuntimeException(
            "Cannot list {$zipPath}: " . trim(implode(' ', array_slice($output, 0, 3)))
        );
    }

    $names = [];

    foreach ($output as $line) {
        $name = zipEntryName(trim($line));

        if ($name !== '' && !str_ends_with($name, '/')) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * Normalise an entry name so the two listing backends agree: no leading "./",
 * no leading slash, forward slashes only.
 */
function zipEntryName(string $name): string
{
    $name = str_replace('\\', '/', $name);

    while (str_starts_with($name, './')) {
        $name = substr($name, 2);
    }

    return ltrim($name, '/');
}
