<?php

declare(strict_types=1);

namespace BoardSim\Infrastructure;

use BoardSim\Domain\Run\RunStore;
use BoardSim\Domain\Run\StoreDiagnostics;
use BoardSim\Domain\Run\StoreUnavailable;
use BoardSim\Domain\Share\ShareStore;

/**
 * Stand-in for both stores when the database cannot be reached.
 *
 * Why this exists: `public/api/index.php` used to construct PDO before routing,
 * so a missing database made *every* route — including `/v1/health` — answer
 * `BOOTSTRAP_FAILED`. On a host with no shell, `/v1/health` is the only place an
 * operator can read what is wrong, so the front controller must stay alive with a
 * broken database and keep answering that one endpoint.
 *
 * Every data operation throws StoreUnavailable (→ HTTP 503 `DB_UNAVAILABLE`).
 * `describe()` still answers, which is how health reports `reachable: false`.
 */
final class UnavailableStore implements RunStore, ShareStore, StoreDiagnostics
{
    public function __construct(private readonly string $reason = 'unavailable')
    {
    }

    public function describe(): array
    {
        return [
            'reachable' => false,
            'schema' => 'unknown',
            'applied' => [],
            'error' => $this->reason,
        ];
    }

    public function missingBundles(array $sha256): array
    {
        return array_values($sha256);
    }

    public function transaction(callable $operation): mixed
    {
        throw $this->fail();
    }

    public function findForUpdate(string $runId): ?array
    {
        throw $this->fail();
    }

    public function delete(string $runId): int
    {
        throw $this->fail();
    }

    public function purgeBefore(string $cutoffIso): int
    {
        throw $this->fail();
    }

    public function create(array $run): void
    {
        throw $this->fail();
    }

    public function find(string $runId): ?array
    {
        throw $this->fail();
    }

    public function update(array $run): void
    {
        throw $this->fail();
    }

    public function appendEvent(array $event): void
    {
        throw $this->fail();
    }

    public function events(string $runId): array
    {
        throw $this->fail();
    }

    public function eventByIdempotencyKey(string $runId, string $key): ?array
    {
        throw $this->fail();
    }

    public function saveBoard(string $runId, array $selections): void
    {
        throw $this->fail();
    }

    public function boardSelections(string $runId): array
    {
        throw $this->fail();
    }

    public function noteBundle(string $sha256, string $kind, string $version, string $payload): void
    {
        throw $this->fail();
    }

    public function put(string $tokenHash, array $record): void
    {
        throw $this->fail();
    }

    public function revoke(string $tokenHash, string $atUtc): bool
    {
        throw $this->fail();
    }

    private function fail(): StoreUnavailable
    {
        return new StoreUnavailable($this->reason);
    }
}
