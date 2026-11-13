<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

use RuntimeException;

/**
 * A rejection the API can map onto an HTTP response without leaking internals.
 *
 * The error code is stable and machine-readable so the client never has to parse
 * a message, and the HTTP status is decided here rather than in the router.
 */
final class DomainError extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what, string $id): self
    {
        return new self('NOT_FOUND', "{$what} '{$id}' does not exist", 404);
    }

    public static function invalid(string $detail): self
    {
        return new self('INVALID_REQUEST', $detail, 400);
    }

    /**
     * Used for board_crisis, which is reserved and not authored in v1.0. 422 is
     * required by the interface specification so the client can render the
     * "Reserved Extension" state rather than a generic failure.
     */
    public static function unsupported(string $detail): self
    {
        return new self('UNSUPPORTED', $detail, 422);
    }

    public static function conflict(string $detail): self
    {
        return new self('CONFLICT', $detail, 409);
    }

    public static function methodNotAllowed(string $method): self
    {
        return new self('METHOD_NOT_ALLOWED', "Method {$method} is not allowed here", 405);
    }

    public static function internal(string $detail): self
    {
        return new self('INTERNAL', $detail, 500);
    }
}
