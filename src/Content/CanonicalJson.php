<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * Canonical JSON serialiser for reproducible bundle hashing.
 *
 * Contract (01_ARCHITECTURE.md, 07_ACCEPTANCE_TESTS.md):
 *   - object keys sorted with a byte-wise comparison, so map ordering in PHP
 *     can never change a hash;
 *   - integers only, never floats, because binary floating point is not exact
 *     and would make the bundle hash platform dependent;
 *   - UTF-8 preserved unescaped so em dashes and Greek text hash identically
 *     across hosts.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                throw new RuntimeException('String is not valid UTF-8 and cannot be canonicalised');
            }

            return $encoded;
        }

        if (is_float($value)) {
            throw new RuntimeException(
                'Floats are not permitted in a canonical scoring payload; use integer units'
            );
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(self::encode(...), $value)) . ']';
            }

            $keys = array_keys($value);
            $keys = array_map(static fn (int|string $key): string => (string) $key, $keys);
            sort($keys, SORT_STRING);

            $parts = [];
            foreach ($keys as $key) {
                $parts[] = self::encode($key) . ':' . self::encode($value[$key]);
            }

            return '{' . implode(',', $parts) . '}';
        }

        throw new RuntimeException('Unsupported value type in canonical payload: ' . get_debug_type($value));
    }

    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }
}
