<?php

declare(strict_types=1);

namespace BoardSim\Domain\Share;

final class InMemoryShareStore implements ShareStore
{
    /** @var array<string, array<string, mixed>> */
    private array $tokens = [];

    public function put(string $tokenHash, array $record): void
    {
        $this->tokens[$tokenHash] = $record;
    }

    public function find(string $tokenHash): ?array
    {
        return $this->tokens[$tokenHash] ?? null;
    }

    public function revoke(string $tokenHash, string $atUtc): bool
    {
        if (!isset($this->tokens[$tokenHash])) {
            return false;
        }

        $this->tokens[$tokenHash]['revoked_at'] = $atUtc;

        return true;
    }

    public function purgeBefore(string $cutoffIso): int
    {
        $removed = 0;

        foreach ($this->tokens as $hash => $record) {
            $expiry = (string) ($record['expires_at'] ?? '');
            $revoked = (string) ($record['revoked_at'] ?? '');

            // Purge on whichever came first: an expired or revoked token has no
            // further use, and keeping it would be retention without purpose.
            $dead = $expiry !== '' && $expiry < $cutoffIso;
            $dead = $dead || ($revoked !== '' && $revoked < $cutoffIso);

            if ($dead) {
                unset($this->tokens[$hash]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->tokens;
    }
}
