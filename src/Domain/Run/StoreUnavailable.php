<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

use RuntimeException;

/**
 * Raised when the configured persistence layer cannot serve a request.
 *
 * The router maps this to HTTP 503 `DB_UNAVAILABLE` rather than the generic 500,
 * because the two failures mean different things to an operator: 500 says the
 * application is broken, 503 says the database is missing, unreachable or not
 * migrated yet — and `/v1/health` will say which.
 *
 * The message carries a short machine-readable code (`missing_config`,
 * `unknown_database`, `access_denied`, `unreachable`, `unavailable`), never a
 * credential and never a raw driver stack trace.
 */
final class StoreUnavailable extends RuntimeException
{
}
