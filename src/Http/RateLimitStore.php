<?php

declare(strict_types=1);

namespace BoardSim\Http;

/**
 * Counter storage for fixed-window rate limiting.
 *
 * Narrow on purpose: one atomic increment per key per window. The limiter owns
 * the policy (limits, windows, client key), so a different backend — MySQL in
 * production, an array in tests — changes nothing about the decisions.
 *
 * The key is always a hash of the client identity, never the address itself:
 * a rate-limit table is not a place to accumulate personal data.
 */
interface RateLimitStore
{
    /**
     * Increment the counter for $key in the window starting at $windowStart.
     *
     * Must be atomic enough that two concurrent requests cannot both read a
     * count below the limit and both proceed.
     *
     * @return int the number of hits recorded in this window, including this one
     */
    public function increment(string $key, int $windowStart): int;
}
