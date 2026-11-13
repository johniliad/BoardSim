<?php

declare(strict_types=1);

/**
 * Rate limiter decisions, exercised without MySQL.
 *
 * The limiter is policy; the store is plumbing. The MySQL store is exercised on
 * the host (migration 004 + real traffic), but every decision it drives —
 * which action, how many requests, when the window resets, what the client is
 * told — is asserted here with an in-memory counter, exactly as the API suite
 * asserts the run lifecycle against an in-memory store.
 *
 *   php bin/verify-ratelimit.php
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Http\InMemoryRateLimitStore;
use BoardSim\Http\RateLimiter;

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

echo "== Action mapping ==\n";

check('GET maps to the read budget', RateLimiter::actionFor('GET', '/v1/glossary') === 'read');
check('HEAD maps to the read budget', RateLimiter::actionFor('HEAD', '/v1/health') === 'read');
check(
    'POST /v1/runs maps to run_create',
    RateLimiter::actionFor('POST', '/v1/runs') === 'run_create'
);
check(
    'POST /v1/runs/{id}/decisions maps to decision',
    RateLimiter::actionFor('POST', '/v1/runs/abc/decisions') === 'decision'
);
check(
    'POST /v1/runs/{id}/board maps to decision',
    RateLimiter::actionFor('POST', '/v1/runs/abc/board') === 'decision'
);
check('POST /v1/shares maps to share', RateLimiter::actionFor('POST', '/v1/shares') === 'share');
check(
    'DELETE /v1/shares/{token} maps to share',
    RateLimiter::actionFor('DELETE', '/v1/shares/deadbeef') === 'share'
);
check(
    'an unknown mutating route is not unlimited',
    RateLimiter::actionFor('POST', '/v1/whatever') === 'decision'
);

echo "\n== Budget enforcement ==\n";

$limiter = new RateLimiter(new InMemoryRateLimitStore());
$now = 1_800_000_010;
$limit = RateLimiter::LIMITS['run_create'];
$allowed = 0;
$blockedAt = null;

for ($i = 1; $i <= $limit + 1; $i++) {
    $verdict = $limiter->check('203.0.113.7', 'run_create', $now);

    if ($verdict['allowed']) {
        $allowed++;
        continue;
    }

    $blockedAt = $i;
    break;
}

check('the run_create budget is exactly the published limit', $allowed === $limit, (string) $allowed);
check('the next request in the same window is refused', $blockedAt === $limit + 1, (string) $blockedAt);
check('remaining never goes negative', $verdict['remaining'] === 0, (string) $verdict['remaining']);
check(
    'Retry-After stays inside the window',
    $verdict['retry_after'] >= 1 && $verdict['retry_after'] <= RateLimiter::WINDOW_SECONDS,
    (string) $verdict['retry_after']
);

echo "\n== Isolation between clients and actions ==\n";

$other = $limiter->check('198.51.100.9', 'run_create', $now);
check('a different client has its own budget', $other['allowed'] === true);

$read = $limiter->check('203.0.113.7', 'read', $now);
check('a different action has its own budget', $read['allowed'] === true);
check(
    'reads have a larger budget than run creation',
    RateLimiter::LIMITS['read'] > RateLimiter::LIMITS['run_create']
);

echo "\n== Window rollover ==\n";

$nextWindow = $limiter->check(
    '203.0.113.7',
    'run_create',
    $now + RateLimiter::WINDOW_SECONDS
);
check('the counter resets in the next window', $nextWindow['allowed'] === true);
check(
    'the reset restores the full budget',
    $nextWindow['remaining'] === $limit - 1,
    (string) $nextWindow['remaining']
);

$lateWindow = 60 - (($now + 30) % 60);
$midWindow = $limiter->check('192.0.2.44', 'share', $now + 30);
check(
    'Retry-After counts down within the window',
    $midWindow['retry_after'] === $lateWindow,
    sprintf('%d vs %d', $midWindow['retry_after'], $lateWindow)
);

echo "\n== Store hygiene ==\n";

$store = new InMemoryRateLimitStore();
$first = $store->increment('key-a', $now);
$second = $store->increment('key-a', $now);
$fresh = $store->increment('key-a', $now + RateLimiter::WINDOW_SECONDS);

check('increments are counted', $first === 1 && $second === 2, $first . ',' . $second);
check('a new window starts from one again', $fresh === 1, (string) $fresh);

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
