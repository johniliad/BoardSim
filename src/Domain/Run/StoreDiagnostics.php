<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

/**
 * Optional capability of a durable store: report on the deployment itself.
 *
 * A store that cannot answer these questions (the in-memory and file stores, the
 * unavailable placeholder) simply does not implement this interface, and health
 * reports `schema: n/a`. Adding it as a separate interface rather than to
 * RunStore keeps the persistence contract narrow: a store must still be able to
 * be written without knowing anything about deployment diagnostics.
 *
 * The point is that `GET /v1/health` — not a CLI script and not an installer —
 * is the deployment's status page. On a host without shell access that is the
 * only place an operator can see whether the schema and the pinned bundles are
 * actually in place.
 */
interface StoreDiagnostics
{
    /**
     * @return array{
     *     reachable: bool,
     *     schema: 'ok'|'partial'|'missing'|'unknown',
     *     applied: list<string>,
     *     error: string|null
     * }
     */
    public function describe(): array;

    /**
     * Which of the pinned bundle hashes are absent from content_bundles.
     *
     * The caller owns the list because the service, not the store, knows which
     * three artefacts it pins.
     *
     * @param list<string> $sha256
     *
     * @return list<string> the hashes that are missing
     */
    public function missingBundles(array $sha256): array;
}
