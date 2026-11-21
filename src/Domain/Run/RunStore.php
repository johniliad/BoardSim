<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

/**
 * Persistence contract for runs.
 *
 * Deliberately narrow: the service can create and update runs, append events and
 * read them back, but there is no method to update or delete an individual event.
 * A store that cannot express history rewriting cannot accidentally perform it.
 */
interface RunStore
{
    /**
     * Execute a run mutation atomically.
     *
     * Production stores must commit every write performed by the callback
     * together, or roll all of them back when it throws. Development stores
     * mirror that contract with snapshots so service tests exercise the same
     * failure semantics as MySQL.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed;

    /**
     * Read a run while holding the store's mutation lock for it.
     *
     * Must be called inside transaction(). MySQL implements this with
     * SELECT ... FOR UPDATE; single-process stores return their current copy.
     *
     * @return array<string, mixed>|null
     */
    public function findForUpdate(string $runId): ?array;

    /**
     * Erase a run and everything attached to it.
     *
     * The one destructive operation here, and it is deliberate: 06 requires
     * deletion, and a deletion that leaves rows behind is not a deletion. Note what
     * it is and is not — this removes the *whole subject*, so it is erasure, not
     * history rewriting. There is still no way to change or drop one event while
     * keeping its run, so a chain can never be edited to look valid.
     *
     * @return int events removed, so the confirmation can state what actually went
     */
    public function delete(string $runId): int;

    /**
     * Physically delete every run whose retention timestamp precedes the cutoff.
     *
     * Used by the retention job, not by the API. Same rules as `delete()`: real
     * removal, no tombstone, and anything attached to the run goes with it.
     *
     * @return list<string> run ids that were removed
     */
    public function purgeBefore(string $cutoffIso);

    /**
     * @param array<string, mixed> $run
     */
    public function create(array $run): void;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $runId): ?array;

    /**
     * @param array<string, mixed> $run
     */
    public function update(array $run): void;

    /**
     * @param array<string, mixed> $event
     */
    public function appendEvent(array $event): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function events(string $runId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function eventByIdempotencyKey(string $runId, string $key): ?array;

    /**
     * @param array<string, string> $selections
     */
    public function saveBoard(string $runId, array $selections): void;

    /**
     * @return array<string, string>
     */
    public function boardSelections(string $runId): array;

    public function noteBundle(string $sha256, string $kind, string $version, string $payload): void;
}
