<?php

declare(strict_types=1);

/**
 * Repository hygiene: no real host path may be committed.
 *
 * BoardSim is published on GitHub, so the repository must not contain the
 * author's deployment paths, and it must not contain anyone else's either. This
 * check is the guard that keeps the `deploy/host.config.example.php` →
 * `deploy/host.config.php` split honest: the example carries placeholders, the
 * real file is gitignored, and nothing else may carry a path at all.
 *
 *   php bin/verify-hygiene.php
 *
 * Allowed placeholders: `/home/USER/`, `/home/YOURUSER/`, and anything under
 * `/home/example/`. Anything else that looks like a home directory fails, as does
 * a Windows user profile path.
 */
$root = dirname(__DIR__);

$directories = ['src', 'bin', 'deploy', 'public', 'docs', 'migrations', 'engine', 'scenarios', 'requirements'];
$files = [
    'README.md',
    'DEPLOYMENT.md',
    'composer.json',
    '.env.example',
    'config.local.example.php',
];

$skip = [
    'public/app',              // built, minified output
    'content/bundles',         // compiled artefacts
    'deploy/host.config.php',  // gitignored local target: real paths belong there
];

$patterns = [
    '#/home/(?!(?:USER|YOURUSER|example)\b)[A-Za-z0-9._-]+#',
    '#[A-Za-z]:[\\\\/]Users[\\\\/]#',
    '#/Users/[A-Za-z0-9._-]+#',
];

$failures = 0;
$scanned = 0;

$report = static function (string $file, int $line, string $pattern) use (&$failures): void {
    $failures++;
    printf("FAIL  %s:%d contains a host path (%s)\n", $file, $line, $pattern);
};

foreach ($patterns as $pattern) {
    foreach ($files as $file) {
        $path = $root . '/' . $file;

        if (!is_file($path)) {
            continue;
        }

        $scanned++;

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            if (preg_match($pattern, $line) === 1) {
                $report($file, $number + 1, $pattern);
            }
        }
    }

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));

            foreach ($skip as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            if (!in_array(strtolower($item->getExtension()), ['php', 'md', 'json', 'yml', 'yaml', 'sql', 'txt', 'htaccess'], true)) {
                continue;
            }

            $scanned++;

            foreach (file($item->getPathname(), FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $report($relative, $number + 1, $pattern);
                }
            }
        }
    }
}

// ── What may be published ────────────────────────────────────────────────────
// The repository is public. Everything tracked is world-readable for ever once
// pushed, so the guard is a list of things that must NOT be tracked — build
// output, credentials, host configuration, archives — and a short list that must
// be, because a fresh clone cannot run without them.
$tracked = [];
$gitStatus = 1;
exec('git -C ' . escapeshellarg($root) . ' ls-files 2>&1', $tracked, $gitStatus);

if ($gitStatus !== 0) {
    echo "note: not a git checkout, skipping the publishable-set check\n";
} else {
    $forbidden = [
        '#^build/#',
        '#^public/app/#',
        '#^(web/)?node_modules/#',
        '#^(var|logs|reports|coverage)/#',
        '#^deploy/host\.config\.php$#',
        '#(^|/)config\.local\.php$#',
        '#(^|/)\.env(\.(?!example$)[A-Za-z0-9]+)?$#',
        '#\.(zip|tar|tar\.gz|tgz|p12|pem|key|crt|pfx|jks|sqlite|sqlite3|db|log)$#',
        '#\.sql\.(gz|zip)$#',
        '#(^|/)\.(idea|vscode|claude|cursor|windsurf|specstory|history|roo)/#',
        '#\.DS_Store$#',
    ];

    $leaks = [];

    foreach ($tracked as $path) {
        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                $leaks[] = $path;
                break;
            }
        }
    }

    if ($leaks === []) {
        printf("PASS  nothing tracked that must not be published (%d file(s))\n", count($tracked));
    } else {
        $failures += count($leaks);

        foreach ($leaks as $leak) {
            printf("FAIL  tracked but must not be published: %s\n", $leak);
        }
    }

    $required = [
        '.env.example',
        'config.local.example.php',
        'deploy/host.config.example.php',
        'composer.json',
        'web/package-lock.json',
        'migrations/004_rate_limits.sql',
        'public/.htaccess',
        'public/robots.txt',
        'public/sitemap.xml',
        'public/llms.txt',
        'web/index.html',
        '.github/workflows/ci.yml',
    ];

    $absent = array_values(array_diff($required, $tracked));

    if ($absent === []) {
        printf("PASS  every file a clone needs is tracked (%d checked)\n", count($required));
    } else {
        $failures += count($absent);

        foreach ($absent as $missing) {
            printf("FAIL  required for a fresh clone but not tracked: %s\n", $missing);
        }
    }
}

printf(
    "hygiene: %s (%d file(s) scanned)\n",
    $failures === 0 ? 'PASS — no host paths in the repository' : "{$failures} host path(s) to remove",
    $scanned
);

exit($failures === 0 ? 0 : 1);
