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
 */
$root = dirname(__DIR__);
$canonical = 'https://boardsim.iliadis.net/';
$ogTitle = 'BoardSim | Executive decision-making under pressure';

$passed = 0;
$failed = 0;

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
}

section('The served shell (source)');
$source = (string) file_get_contents($root . '/web/index.html');
checkShell('web/index.html', $source, $canonical, $ogTitle);

section('The served shell (build output)');
$builtPath = $root . '/public/app/index.html';

if (!is_file($builtPath)) {
    check('public/app/index.html exists', false, 'run cd web && npm run build');
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

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
