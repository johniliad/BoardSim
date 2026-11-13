<?php

declare(strict_types=1);

namespace BoardSim\Domain\Share;

/**
 * Storage for share tokens.
 *
 * A token record is deliberately thin: a hash, the run ids it exposes, and three
 * timestamps. It holds no score and no dimension, so revoking a token cannot
 * change what a run says — it only removes access to it.
 */
interface ShareStore
{
    /**
     * @param array<string, mixed> $record
     */
    public function put(string $tokenHash, array $record): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $tokenHash): ?array;

    /**
     * Mark a token revoked. Kept rather than deleted so a second revoke can answer
     * honestly instead of pretending the token never existed.
     */
    public function revoke(string $tokenHash, string $atUtc): bool;

    /**
     * Housekeeping: remove tokens that expired or were revoked before the cutoff.
     *
     * @return int rows removed
     */
    public function purgeBefore(string $cutoffIso): int;
}
