<?php

declare(strict_types=1);

/**
 * Static metadata and machine-discovery checks.
 *
 * The product is a client-rendered SPA, so anything a crawler or a language
 * model needs must be in the *served HTML*, not injected by JavaScript. This
 * script reads the shell, the discovery files and the routing rules and asserts
 * they say the same thing, statically:
 *
 *   - web/index.html and its build output public/app/index.html carry the full
 *     identity set (title, description, author, canonical, Open Graph, JSON-LD);
 *   - the JSON-LD block parses as JSON and declares a schema.org WebApplication;
 *   - robots.txt, sitemap.xml and llms.txt exist and point at the same canonical;
 *   - .htaccess serves those files as files and redirects the duplicate shell.
 *
 *   php bin/verify-meta.php
 *   php bin/verify-meta.php --require-build   # CI: the SPA must have been built
 *
 * `public/app/` is generated and gitignored, so a fresh clone has no built shell.
 * By default the build-output half is reported as SKIP with the command that
 * fixes it; `--require-build` turns the absence into a failure, which is what the
 * CI front-end job wants after it has run `npm run build`.
 */
$root = dirname(__DIR__);
$canonical = 'https://boardsim.iliadis.net/';
$ogTitle = 'BoardSim | Executive decision-making under pressure';
$requireBuild = in_array('--require-build', $argv ?? [], true);

$passed = 0;
$failed = 0;
$skipped = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("  PASS  %s\n", $label);

        return;
    }

    $failed++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : ' — ' . $detail);
}

function section(string $title): void
{
    printf("\n== %s ==\n", $title);
}

/**
 * Report something that cannot be measured here, without pretending it passed.
 */
function skip(string $label, string $reason): void
{
    global $skipped;

    $skipped++;
    printf("  SKIP  %s — %s\n", $label, $reason);
}

/**
 * Assert the identity set in one HTML document.
 */
function checkShell(string $label, string $html, string $canonical, string $ogTitle): void
{
    $missing = [];

    $required = [
        '<title>' . $ogTitle . '</title>' => 'the exact title',
        '<link rel="canonical" href="' . $canonical . '"' => 'the canonical URL',
        'name="author" content="Dr. John Iliadis"' => 'the author meta',
        '<link rel="author" href="https://www.iliadis.net/"' => 'the author link',
        'property="og:type" content="website"' => 'og:type',
        'property="og:site_name" content="BoardSim"' => 'og:site_name',
        'property="og:title" content="' . $ogTitle . '"' => 'og:title',
        'property="og:url" content="' . $canonical . '"' => 'og:url',
        'property="og:description"' => 'og:description',
        'name="twitter:card"' => 'the Twitter card',
        '"@type": "WebApplication"' => 'the WebApplication type',
        '"@context": "https://schema.org"' => 'the schema.org context',
    ];

    foreach ($required as $needle => $description) {
        if (!str_contains($html, $needle)) {
            $missing[] = $description;
        }
    }

    check($label . ' carries the full identity set', $missing === [], implode(', ', $missing));

    // The structured data must be valid JSON, not just plausible text.
    if (preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $block) !== 1) {
        check($label . ' has one JSON-LD block', false, 'no ld+json script found');

        return;
    }

    $data = json_decode(trim($block[1]), true);

    check($label . ' has one JSON-LD block that parses', is_array($data), json_last_error_msg());

    if (!is_array($data)) {
        return;
    }

    $required = [
        'name' => 'BoardSim',
        'url' => $canonical,
        'applicationCategory' => 'BusinessApplication',
        'inLanguage' => 'en',
    ];

    $wrong = [];

    foreach ($required as $key => $expected) {
        if (($data[$key] ?? null) !== $expected) {
            $wrong[] = $key;
        }
    }

    check($label . ' JSON-LD names the application', $wrong === [], implode(', ', $wrong));
    check(
        $label . ' JSON-LD names the author',
        ($data['author']['@type'] ?? null) === 'Person'
            && ($data['author']['name'] ?? null) === 'Dr. John Iliadis'
    );
    check(
        $label . ' JSON-LD states the application is free',
        ($data['offers']['price'] ?? null) === '0'
    );
    check(
        $label . ' JSON-LD describes features a reader can verify',
        is_array($data['featureList'] ?? null) && count($data['featureList']) >= 3
    );
    check(
        $label . ' JSON-LD points at the logo that is actually served',
        ($data['logo'] ?? null) === $canonical . 'app/boardsim-mark.svg'
            && ($data['image'] ?? null) === $canonical . 'app/boardsim-logo.svg'
    );
}

section('The served shell (source)');
$source = (string) file_get_contents($root . '/web/index.html');
checkShell('web/index.html', $source, $canonical, $ogTitle);

section('The served shell (build output)');
$builtPath = $root . '/public/app/index.html';

if (!is_file($builtPath)) {
    // public/app/ is generated and gitignored: a clean checkout legitimately has
    // no built shell. Failing here would make the check order-dependent, which is
    // how it produced a red build on a machine that had simply not built yet.
    if ($requireBuild) {
        check(
            'public/app/index.html exists',
            false,
            'run cd web && npm run build first (--require-build makes this fatal)'
        );
    } else {
        skip(
            'the built shell',
            'public/app/index.html is not built — run cd web && npm run build, '
            . 'or pass --require-build to make its absence fatal'
        );
    }
} else {
    checkShell('public/app/index.html', (string) file_get_contents($builtPath), $canonical, $ogTitle);
}

section('robots.txt');

$robots = (string) file_get_contents($root . '/public/robots.txt');

check('robots.txt allows crawling', preg_match('/User-agent: \*\RAllow: \//', $robots) === 1);
check(
    'robots.txt advertises the sitemap',
    str_contains($robots, 'Sitemap: ' . $canonical . 'sitemap.xml')
);
check(
    'robots.txt explains why runs are unlisted',
    str_contains($robots, 'share') && str_contains($robots, 'no-store')
);

section('sitemap.xml');

$sitemap = (string) file_get_contents($root . '/public/sitemap.xml');

check('sitemap.xml lists the canonical page', str_contains($sitemap, '<loc>' . $canonical . '</loc>'));
check(
    'sitemap.xml carries a build-time lastmod placeholder',
    str_contains($sitemap, '<lastmod>__LASTMOD__</lastmod>'),
    'bin/build-deploy.php stamps it; without it the date would never move'
);
check(
    'sitemap.xml does not enumerate runs or share links',
    !str_contains($sitemap, '/v1/') && !str_contains($sitemap, '/runs/')
);

section('llms.txt');

$llms = (string) file_get_contents($root . '/public/llms.txt');

check('llms.txt starts with the product name', str_starts_with($llms, '# BoardSim'));
check('llms.txt opens with a summary blockquote', preg_match('/\n> /', $llms) === 1);
check('llms.txt states the canonical URL', str_contains($llms, 'Canonical: ' . $canonical));

$endpoints = ['/v1/health', '/v1/methodology', '/v1/scenarios', '/v1/glossary', '/v1/runs/{id}/explain'];

foreach ($endpoints as $endpoint) {
    check('llms.txt names ' . $endpoint, str_contains($llms, $endpoint));
}

check('llms.txt states what the product is not', str_contains($llms, '## What it is not'));
check('llms.txt names the author', str_contains($llms, 'Dr. John Iliadis'));

section('The logo');

$markFile = $root . '/web/public/boardsim-mark.svg';
$lockupFile = $root . '/web/public/boardsim-logo.svg';
$faviconFile = $root . '/web/public/favicon.svg';
$knightHead = 'M 22,10 C 32.5,11 38.5,18 38,39';

foreach ([$markFile, $lockupFile, $faviconFile] as $asset) {
    $svg = is_file($asset) ? (string) file_get_contents($asset) : '';

    check(
        basename($asset) . ' exists and parses as SVG',
        $svg !== ''
            && str_contains($svg, 'viewBox=')
            && @simplexml_load_string($svg) !== false
    );
}

$markSvg = (string) file_get_contents($markFile);

check(
    'the mark is the knight, drawn in the brand gradient',
    str_contains($markSvg, $knightHead)
        && str_contains($markSvg, '#0b2540')
        && str_contains($markSvg, '#2f92e8')
);

$lockupSvg = (string) file_get_contents($lockupFile);

check(
    'the lockup sets the wordmark in navy and blue',
    str_contains($lockupSvg, '<tspan fill="#10263f">Board</tspan>')
        && str_contains($lockupSvg, '<tspan fill="#1b76d2">Sim</tspan>')
        && str_contains($lockupSvg, $knightHead)
);

check(
    'the favicon is the same knight, not a second design',
    str_contains((string) file_get_contents($faviconFile), $knightHead)
);

$appSource = (string) file_get_contents($root . '/web/src/App.tsx');
$homeSource = (string) file_get_contents($root . '/web/src/Home.tsx');

check('the header renders the mark', str_contains($appSource, 'src="/app/boardsim-mark.svg"'));
check('the homepage renders the lockup', str_contains($homeSource, 'src="/app/boardsim-logo.svg"'));
check(
    'the header no longer draws its own letters',
    preg_match('#className="bs-brand__mark"[^>]*>\s*BS#', $appSource) !== 1
);
check(
    'both images declare their size, so nothing shifts while they load',
    str_contains($appSource, 'width={42}')
        && str_contains($appSource, 'height={42}')
        && str_contains($homeSource, 'width={320}')
        && str_contains($homeSource, 'height={88}')
);
check(
    'the favicon is declared in the served shell',
    str_contains($source, 'rel="icon" href="/app/favicon.svg"')
);

section('Routing rules');

$htaccess = (string) file_get_contents($root . '/public/.htaccess');

check(
    'existing files are served before the SPA fallback',
    str_contains($htaccess, 'RewriteCond %{REQUEST_FILENAME} -f')
);
check(
    'the duplicate shell address is redirected',
    str_contains($htaccess, 'THE_REQUEST')
        && str_contains($htaccess, 'app/index\.html')
        && str_contains($htaccess, '[R=301,L]')
);
check(
    'discovery files are cached briefly, not immutably',
    str_contains($htaccess, 'robots\.txt|llms\.txt|sitemap\.xml')
        && str_contains($htaccess, 'max-age=3600')
);
check(
    'llms.txt is stated as plain text',
    str_contains($htaccess, '<Files "llms.txt">')
);

// "/" is a directory, so the real-file test stops the ruleset before the SPA
// fallback and Apache looks for a DirectoryIndex the build does not create. That
// returned 403 to every crawler while a browser still rendered the app. The rule
// that fixes it must exist, and it must come before the file test.
$rootRule = strpos($htaccess, 'RewriteRule ^$ app/index.html [L]');
$fileTest = strpos($htaccess, 'RewriteCond %{REQUEST_FILENAME} -f [OR]');

check(
    'the document root is mapped to the shell before the real-file test',
    $rootRule !== false && $fileTest !== false && $rootRule < $fileTest
);
check(
    'a missing discovery file is a 404, not the SPA fallback',
    str_contains($htaccess, 'RewriteRule ^(robots\\.txt|sitemap\\.xml|llms\\.txt)$ - [R=404,L]')
);

section('One canonical origin');

$origins = [];

foreach (['web/index.html', 'public/robots.txt', 'public/sitemap.xml', 'public/llms.txt'] as $file) {
    preg_match_all('#https://[a-z0-9.-]+#', (string) file_get_contents($root . '/' . $file), $found);
    $origins = array_merge($origins, $found[0]);
}

$origins = array_values(array_unique($origins));
$productOrigins = array_values(array_filter(
    $origins,
    static fn (string $origin): bool => !in_array($origin, ['https://www.iliadis.net', 'https://schema.org'], true)
        && !str_starts_with($origin, 'https://boardsim.iliadis.net')
));

check(
    'every product URL names boardsim.iliadis.net',
    $productOrigins === [],
    implode(', ', $productOrigins)
);
check('the author URL is the one in the repo', in_array('https://www.iliadis.net', $origins, true));

printf("\n%d passed, %d failed, %d skipped\n", $passed, $failed, $skipped);

if ($skipped > 0) {
    printf("Skipped checks are optional inputs, not passes: see the SKIP lines above.\n");
}

exit($failed === 0 ? 0 : 1);
