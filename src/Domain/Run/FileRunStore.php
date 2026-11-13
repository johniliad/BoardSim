<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

/**
 * Development store: an InMemoryRunStore persisted to a JSON file between
 * requests.
 *
 * PHP's built-in server re-executes the script per request, so a purely in-memory
 * store loses every run immediately. This wrapper exists solely so the front end
 * can be developed against the real service without MySQL. It is not safe for
 * concurrent writers and must never be used in production.
 */
final class FileRunStore implements RunStore
{
    private InMemoryRunStore $inner;

    /** @var array<string, mixed> */
    private array $state;

    /** @var array<string, true> run ids known to this store, including new ones */
    private array $runIds = [];

    public function __construct(private readonly string $path)
    {
        $this->state = $this->read();
        $this->inner = new InMemoryRunStore();
        $this->hydrate();
    }

    public function create(array $run): void
    {
        $this->inner->create($run);
        $this->runIds[(string) $run['run_id']] = true;
        $this->persist();
    }

    public function find(string $runId): ?array
    {
        return $this->inner->find($runId);
    }

    public function update(array $run): void
    {
        $this->inner->update($run);
        $this->persist();
    }

    public function appendEvent(array $event): void
    {
        $this->inner->appendEvent($event);
        $this->persist();
    }

    public function events(string $runId): array
    {
        return $this->inner->events($runId);
    }

    public function eventByIdempotencyKey(string $runId, string $key): ?array
    {
        return $this->inner->eventByIdempotencyKey($runId, $key);
    }

    public function saveBoard(string $runId, array $selections): void
    {
        $this->inner->saveBoard($runId, $selections);
        $this->persist();
    }

    public function delete(string $runId): int
    {
        $removed = $this->inner->delete($runId);
        $this->persist();

        return $removed;
    }

    /**
     * @return list<string>
     */
    public function purgeBefore(string $cutoffIso): array
    {
        $purged = $this->inner->purgeBefore($cutoffIso);
        $this->persist();

        return $purged;
    }

    public function boardSelections(string $runId): array
    {
        return $this->inner->boardSelections($runId);
    }

    public function noteBundle(string $sha256, string $kind, string $version, string $payload): void
    {
        $this->inner->noteBundle($sha256, $kind, $version, $payload);
        $this->persist();
    }

    private function hydrate(): void
    {
        foreach ((array) ($this->state['runs'] ?? []) as $run) {
            $this->inner->create($run);
            $this->runIds[(string) $run['run_id']] = true;
        }

        foreach ((array) ($this->state['events'] ?? []) as $event) {
            $this->inner->appendEvent($event);
            $this->runIds[(string) $event['run_id']] = true;
        }

        foreach ((array) ($this->state['board'] ?? []) as $runId => $selections) {
            $this->inner->saveBoard((string) $runId, (array) $selections);
            $this->runIds[(string) $runId] = true;
        }
    }

    private function persist(): void
    {
        $runs = [];
        $events = [];
        $board = [];
        $runIds = $this->knownRunIds();

        foreach ($runIds as $runId) {
            $run = $this->inner->find($runId);

            if ($run !== null) {
                $runs[] = $run;
                $events = array_merge($events, $this->inner->events($runId));
                $board[$runId] = $this->inner->boardSelections($runId);
            }
        }

        $this->state = ['runs' => $runs, 'events' => $events, 'board' => $board];

        $directory = dirname($this->path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($this->path, json_encode($this->state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return list<string>
     */
    private function knownRunIds(): array
    {
        return array_keys($this->runIds);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
