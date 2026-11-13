<?php

declare(strict_types=1);

namespace BoardSim\Domain\Share;

use RuntimeException;

/**
 * File-backed share tokens, for development.
 *
 * PHP re-executes the front controller per request, so tokens must outlive a
 * request even locally or a created link would be dead on arrival. A JSON file is
 * enough here and is never enabled on a public host.
 */
final class FileShareStore implements ShareStore
{
    /** @var array<string, array<string, mixed>> */
    private array $tokens = [];

    public function __construct(private readonly string $path)
    {
        if (is_file($this->path)) {
            $raw = file_get_contents($this->path);
            $decoded = json_decode($raw === false ? '' : $raw, true);

            if (is_array($decoded)) {
                $this->tokens = $decoded;
            }
        }
    }

    public function put(string $tokenHash, array $record): void
    {
        $this->tokens[$tokenHash] = $record;
        $this->persist();
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
        $this->persist();

        return true;
    }

    public function purgeBefore(string $cutoffIso): int
    {
        $removed = 0;

        foreach ($this->tokens as $hash => $record) {
            $expiry = (string) ($record['expires_at'] ?? '');
            $revoked = (string) ($record['revoked_at'] ?? '');

            if (($expiry !== '' && $expiry < $cutoffIso)
                || ($revoked !== '' && $revoked < $cutoffIso)
            ) {
                unset($this->tokens[$hash]);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->persist();
        }

        return $removed;
    }

    private function persist(): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create share store directory {$directory}");
        }

        file_put_contents(
            $this->path,
            json_encode($this->tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }
}
