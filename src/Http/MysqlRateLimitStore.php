<?php

declare(strict_types=1);

namespace BoardSim\Http;

use PDO;
use Throwable;

/**
 * MySQL counter store for the rate limiter.
 *
 * One row per (hashed client key, action) window, so the table stays the size of
 * "clients active in the last minute" rather than of all traffic. Old rows are
 * removed opportunistically while incrementing.
 */
final class MysqlRateLimitStore implements RateLimitStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function increment(string $key, int $windowStart): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, window_start, hits) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start = ?, hits + 1, 1),
               window_start = IF(window_start = ?, window_start, ?)'
        );

        $statement->execute([$key, $windowStart, $windowStart, $windowStart, $windowStart]);

        $read = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket_key = ?');
        $read->execute([$key]);
        $hits = (int) $read->fetchColumn();

        // Roughly one request in a hundred sweeps rows from finished windows, so
        // the table cannot grow without a second scheduled job.
        if (random_int(1, 100) === 1) {
            try {
                $this->pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')
                    ->execute([$windowStart - 300]);
            } catch (Throwable $e) {
                error_log('BoardSim rate-limit sweep: ' . $e->getMessage());
            }
        }

        return $hits;
    }
}
