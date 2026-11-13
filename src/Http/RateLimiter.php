<?php

declare(strict_types=1);

namespace BoardSim\Http;

/**
 * Fixed-window rate limiter for the public API.
 *
 * The product has no login, so the only cost of abuse is the cost of writing
 * rows: one POST can create a run, and a decision POST writes an event. A single
 * client should not be able to do that without bound, and a shared host should
 * not have to absorb it.
 *
 * Deliberately simple: a one-minute fixed window per (client, action). It is not
 * a defence against a distributed attacker — that belongs at the edge — but it
 * removes the trivial single-source flood and makes the limit explicit in code
 * rather than folklore.
 *
 * The client key is hashed before it reaches the store, so the rate-limit table
 * holds no addresses, only digests that expire within the window.
 */
final class RateLimiter
{
    /** Requests allowed per client per minute, by action. */
    public const LIMITS = [
        // Creating a run is the most expensive write: 27 possible paths of content.
        'run_create' => 10,
        // A decision or board submission per run; a legitimate client makes four.
        'decision' => 30,
        // Share-link minting and revocation.
        'share' => 10,
        // Catalogue, health, glossary, explanations: reads are cheap but not free.
        'read' => 120,
    ];

    public const WINDOW_SECONDS = 60;

    public function __construct(private readonly RateLimitStore $store)
    {
    }

    /**
     * Which limit applies to this request.
     *
     * Unknown mutating routes fall back to `decision`, the mid-range write
     * budget, so a new endpoint cannot accidentally arrive unlimited.
     */
    public static function actionFor(string $method, string $path): string
    {
        $method = strtoupper($method);
        $path = (string) (parse_url($path, PHP_URL_PATH) ?? $path);

        if ($method === 'GET' || $method === 'HEAD') {
            return 'read';
        }

        if (preg_match('#/v1/runs/?$#', $path) === 1) {
            return 'run_create';
        }

        if (preg_match('#/v1/shares(/|$)#', $path) === 1) {
            return 'share';
        }

        return 'decision';
    }

    /**
     * @return array{allowed: bool, action: string, limit: int, remaining: int, retry_after: int}
     */
    public function check(string $clientIdentity, string $action, ?int $now = null): array
    {
        $limit = self::LIMITS[$action] ?? self::LIMITS['decision'];
        $now = $now ?? time();
        $windowStart = $now - ($now % self::WINDOW_SECONDS);

        $key = hash('sha256', $clientIdentity . '|' . $action . '|' . $windowStart);
        $hits = $this->store->increment($key, $windowStart);

        return [
            'allowed' => $hits <= $limit,
            'action' => $action,
            'limit' => $limit,
            'remaining' => max(0, $limit - $hits),
            'retry_after' => max(1, $windowStart + self::WINDOW_SECONDS - $now),
        ];
    }
}
