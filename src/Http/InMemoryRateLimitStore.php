<?php

declare(strict_types=1);

namespace BoardSim\Http;

/**
 * In-memory counter store, for the assertion suite only.
 *
 * PHP re-executes the script per request, so an array cannot limit a real
 * deployment; it exists so the limiter's decisions can be verified on a machine
 * without MySQL, exactly as the in-memory run store does for the API.
 */
final class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, array{window: int, hits: int}> */
    private array $counters = [];

    public function increment(string $key, int $windowStart): int
    {
        $current = $this->counters[$key] ?? null;

        if ($current === null || $current['window'] !== $windowStart) {
            $this->counters[$key] = ['window' => $windowStart, 'hits' => 1];

            return 1;
        }

        $this->counters[$key]['hits']++;

        return $this->counters[$key]['hits'];
    }
}
