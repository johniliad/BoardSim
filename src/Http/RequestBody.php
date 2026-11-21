<?php

declare(strict_types=1);

namespace BoardSim\Http;

use BoardSim\Domain\Run\DomainError;
use JsonException;

/** Bounded JSON-object reader for the API front controller. */
final class RequestBody
{
    public const MAX_BYTES = 16_384;
    public const MAX_DEPTH = 16;

    /**
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public static function read(array $server, string $stream = 'php://input'): array
    {
        $declared = $server['CONTENT_LENGTH'] ?? null;

        if ($declared !== null && $declared !== '') {
            if (!is_scalar($declared) || preg_match('/^\d+$/', (string) $declared) !== 1) {
                throw DomainError::invalid('Content-Length must be a non-negative integer');
            }

            if ((int) $declared > self::MAX_BYTES) {
                throw DomainError::tooLarge(self::MAX_BYTES);
            }
        }

        $raw = file_get_contents($stream, false, null, 0, self::MAX_BYTES + 1);

        if ($raw === false) {
            throw DomainError::invalid('Request body could not be read');
        }

        return self::decode($raw);
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw DomainError::tooLarge(self::MAX_BYTES);
        }

        if (trim($raw) === '') {
            return [];
        }

        try {
            $object = json_decode($raw, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
            $decoded = json_decode($raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DomainError::invalid('Request body must be valid JSON with at most ' . self::MAX_DEPTH . ' nesting levels');
        }

        if (!$object instanceof \stdClass || !is_array($decoded)) {
            throw DomainError::invalid('Request body must be a JSON object');
        }

        return $decoded;
    }
}