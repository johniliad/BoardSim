<?php

declare(strict_types=1);

namespace BoardSim\Infrastructure;

use BoardSim\Domain\Share\ShareStore;

final class MysqlShareStore implements ShareStore
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function put(string $tokenHash, array $record): void
    {
        $sql = 'INSERT INTO share_tokens (token_sha256, run_ids, created_at, expires_at, revoked_at)
                VALUES (:token_sha256, :run_ids, :created_at, :expires_at, :revoked_at)';

        $this->pdo->prepare($sql)->execute([
            'token_sha256' => $tokenHash,
            'run_ids' => json_encode($record['run_ids'], JSON_THROW_ON_ERROR),
            'created_at' => $record['created_at'],
            'expires_at' => $record['expires_at'],
            'revoked_at' => $record['revoked_at'],
        ]);
    }

    public function find(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM share_tokens WHERE token_sha256 = :hash');
        $statement->execute(['hash' => $tokenHash]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['run_ids'] = json_decode((string) $row['run_ids'], true) ?: [];

        return $row;
    }

    public function revoke(string $tokenHash, string $atUtc): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE share_tokens SET revoked_at = :at WHERE token_sha256 = :hash AND revoked_at IS NULL'
        );
        $statement->execute(['at' => $atUtc, 'hash' => $tokenHash]);

        if ($statement->rowCount() > 0) {
            return true;
        }

        // Already revoked still counts as "the token exists and is revoked".
        $exists = $this->pdo->prepare('SELECT 1 FROM share_tokens WHERE token_sha256 = :hash');
        $exists->execute(['hash' => $tokenHash]);

        return $exists->fetchColumn() !== false;
    }

    public function purgeBefore(string $cutoffIso): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM share_tokens
             WHERE expires_at <= :cutoff OR (revoked_at IS NOT NULL AND revoked_at <= :cutoff)'
        );
        $statement->execute(['cutoff' => $cutoffIso]);

        return $statement->rowCount();
    }
}
