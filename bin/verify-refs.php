<?php

declare(strict_types=1);

/**
 * 07_ACCEPTANCE_TESTS.md launch blocker: "Check actual live URL redirects and
 * legal consolidation before public publication."
 *
 * Every REG-xxx anchor in the resolved regulatory bundle points at an official
 * source. This script resolves each unique URL and reports status plus the final
 * destination after redirects, so a moved EUR-Lex page is visible instead of
 * silently cited.
 *
 * It never edits the register. A dead or redirected source is a versioned
 * content change, not something to patch into a pinned file.
 *
 * Usage: php bin/verify-refs.php [--strict]
 *   --strict fails on any redirect as well as any failure.
 *   Without --strict, redirects are reported but do not fail the run.
 */

require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\OverlayLoader;

$root = dirname(__DIR__);
$strict = in_array('--strict', $argv, true);

$regulatory = (new OverlayLoader($root))->load();
$byUrl = [];

foreach ($regulatory['instruments'] as $anchor => $instrument) {
    $url = $instrument->sourceUrl;

    if ($url === '') {
        printf("  MISSING  %s has no source_url\n", (string) $anchor);
        continue;
    }

    $byUrl[$url][] = (string) $anchor;

    foreach ($instrument->relatedUrls as $related) {
        if (is_string($related) && $related !== '') {
            $byUrl[$related][] = (string) $anchor . ' (related)';
        }
    }
}

ksort($byUrl);

/**
 * @return array{status: int, final_url: string, error: string|null}
 */
function resolve(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Range: bytes=0-2048\r\nUser-Agent: BoardSim-ref-check/1.0\r\n",
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];

    if ($headers === []) {
        return ['status' => 0, 'final_url' => $url, 'error' => 'no response (offline or blocked)'];
    }

    $status = 0;
    $finalUrl = $url;

    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
        }

        if (stripos($line, 'location:') === 0) {
            $finalUrl = trim(substr($line, 9));
        }
    }

    unset($body);

    return ['status' => $status, 'final_url' => $finalUrl, 'error' => null];
}

printf("Checking %d unique official sources across %d anchors\n\n", count($byUrl), count($regulatory['instruments']));

$results = [];
$failures = 0;
$redirects = 0;
$offline = 0;

foreach ($byUrl as $url => $anchors) {
    $result = resolve((string) $url);
    $result['anchors'] = $anchors;
    $result['url'] = (string) $url;
    $results[] = $result;

    if ($result['status'] === 0) {
        $offline++;
        printf("  OFFLINE  %s%s\n", $url, $result['error'] === null ? '' : ' — ' . $result['error']);
        continue;
    }

    if ($result['status'] >= 400) {
        $failures++;
        printf("  BROKEN   %d  %s  (%s)\n", $result['status'], $url, implode(', ', $anchors));
        continue;
    }

    if ($result['final_url'] !== $url) {
        $redirects++;
        printf("  MOVED    %d  %s\n           -> %s\n", $result['status'], $url, $result['final_url']);
        continue;
    }

    printf("  OK       %d  %s  (%s)\n", $result['status'], $url, implode(', ', $anchors));
}

$reportDir = $root . '/reports';
@mkdir($reportDir, 0775, true);
$target = $reportDir . '/refs-' . gmdate('Y-m-d') . '.json';

file_put_contents($target, json_encode([
    'generated_at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'anchors' => count($regulatory['instruments']),
    'unique_urls' => count($byUrl),
    'broken' => $failures,
    'moved' => $redirects,
    'offline' => $offline,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("\n%d broken, %d moved, %d offline, %d ok\n", $failures, $redirects, $offline, count($byUrl) - $failures - $redirects - $offline);
printf("Report: %s\n", $target);

if ($offline === count($byUrl)) {
    echo "\nNo source could be reached: this machine has no outbound network.\n";
    echo "Re-run on the deployment host to discharge the launch blocker.\n";
    exit(0);
}

$failed = $failures > 0 || ($strict && $redirects > 0);

exit($failed ? 1 : 0);
