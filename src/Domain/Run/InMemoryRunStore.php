<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

/**
 * In-memory RunStore used by the test harness.
 *
 * This exists because the development machine has no MySQL server, and because a
 * service tested only through its production adapter is tested late. The service
 * is exercised against this store with the same call sequence the HTTP layer
 * uses, so the state machine, duplicate-key behavior and event-link hash chain are exercised here.
 */
final class InMemoryRunStore implements RunStore
{
    /** @var array<string, array<string, mixed>> */
    private array $runs = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $events = [];

    /** @var array<string, array<string, string>> */
    private array $board = [];

    /** @var array<string, array<string, string>> */
    private array $bundles = [];

    private bool $inTransaction = false;

    private bool $failNextUpdateForTest = false;

    public function transaction(callable $operation): mixed
    {
        if ($this->inTransaction) {
            return $operation();
        }

        $snapshot = [$this->runs, $this->events, $this->board, $this->bundles];
        $this->inTransaction = true;

        try {
            $result = $operation();
            $this->inTransaction = false;

            return $result;
        } catch (\Throwable $e) {
            [$this->runs, $this->events, $this->board, $this->bundles] = $snapshot;
            $this->inTransaction = false;

            throw $e;
        }
    }

    public function findForUpdate(string $runId): ?array
    {
        return $this->find($runId);
    }

    public function create(array $run): void
    {
        $runId = (string) $run['run_id'];

        if (isset($this->runs[$runId])) {
            throw DomainError::conflict("Run {$runId} already exists");
        }

        $this->runs[$runId] = $run;
        $this->events[$runId] = [];
    }

    public function find(string $runId): ?array
    {
        return $this->runs[$runId] ?? null;
    }

    public function update(array $run): void
    {
        if ($this->failNextUpdateForTest) {
            $this->failNextUpdateForTest = false;

            throw new \RuntimeException('Injected run update failure');
        }

        $this->runs[(string) $run['run_id']] = $run;
    }

    public function appendEvent(array $event): void
    {
        $runId = (string) $event['run_id'];

        foreach ($this->events[$runId] ?? [] as $existing) {
            if ($existing['idempotency_key'] === $event['idempotency_key']) {
                throw new \RuntimeException('Duplicate idempotency key reached the store');
            }
        }

        $this->events[$runId][] = $event;
    }

    public function events(string $runId): array
    {
        return $this->events[$runId] ?? [];
    }

    public function eventByIdempotencyKey(string $runId, string $key): ?array
    {
        foreach ($this->events[$runId] ?? [] as $event) {
            if ($event['idempotency_key'] === $key) {
                return $event;
            }
        }

        return null;
    }

    public function saveBoard(string $runId, array $selections): void
    {
        $this->board[$runId] = $selections;
    }

    /**
     * @return list<string>
     */
    public function purgeBefore(string $cutoffIso): array
    {
        $purged = [];

        foreach (array_keys($this->runs) as $runId) {
            $run = $this->runs[$runId] ?? null;

            if ($run === null) {
                continue;
            }

            $lastActivity = (string) ($run['finalized_at'] ?? $run['created_at'] ?? '');

            if ($lastActivity !== '' && $lastActivity < $cutoffIso) {
                $this->delete((string) $runId);
                $purged[] = (string) $runId;
            }
        }

        return $purged;
    }

    public function delete(string $runId): int
    {
        if (!isset($this->runs[$runId])) {
            return 0;
        }

        $removed = count($this->events[$runId] ?? []);

        unset($this->runs[$runId], $this->events[$runId], $this->board[$runId]);

        return $removed;
    }

    public function boardSelections(string $runId): array
    {
        return $this->board[$runId] ?? [];
    }

    public function noteBundle(string $sha256, string $kind, string $version, string $payload): void
    {
        $this->bundles[$sha256] = ['kind' => $kind, 'version' => $version, 'payload' => $payload];
    }

    /**
     * Test-only helper: simulate tampering so the hash chain can be proven to
     * detect it. Production stores have no equivalent operation.
     */
    public function tamperEventForTest(string $runId, int $sequence, string $field, mixed $value): void
    {
        foreach ($this->events[$runId] ?? [] as $index => $event) {
            if ((int) $event['sequence'] === $sequence) {
                $this->events[$runId][$index][$field] = $value;

                return;
            }
        }

        throw new \RuntimeException("No event {$sequence} in run {$runId}");
    }

    /** Test-only helper: mutate a persisted run row without recomputing its hash. */
    public function tamperRunForTest(string $runId, string $field, mixed $value): void
    {
        if (!isset($this->runs[$runId])) {
            throw new \RuntimeException("No run {$runId}");
        }

        $this->runs[$runId][$field] = $value;
    }

    /** Test-only helper: mutate board evidence without recomputing board_sha256. */
    public function tamperBoardForTest(string $runId, string $field, string $value): void
    {
        if (!isset($this->board[$runId])) {
            throw new \RuntimeException("No board selections for run {$runId}");
        }

        $this->board[$runId][$field] = $value;
    }

    /** Test-only helper: prove service transactions roll back late failures. */
    public function failNextUpdateForTest(): void
    {
        $this->failNextUpdateForTest = true;
    }

    /**
     * Test helper: how many distinct bundles have been pinned.
     */
    public function bundleCount(): int
    {
        return count($this->bundles);
    }
}
