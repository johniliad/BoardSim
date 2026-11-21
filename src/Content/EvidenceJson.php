<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * Deterministic JSON for non-scoring evidence such as authored consequence data.
 *
 * CanonicalJson deliberately rejects floats because scoring uses integer units.
 * Authored teaching effects are outside scoring and may contain finite decimals,
 * so they need a separate encoder rather than weakening the scoring contract.
 */
final class EvidenceJson
{
    public static function encode(mixed $value): string
    {
        if (is_float($value) && !is_finite($value)) {
            throw new RuntimeException('Non-finite numbers are not valid evidence JSON');
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(self::encode(...), $value)) . ']';
            }

            $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($value));
            sort($keys, SORT_STRING);
            $parts = [];

            foreach ($keys as $key) {
                $parts[] = self::encode($key) . ':' . self::encode($value[$key]);
            }

            return '{' . implode(',', $parts) . '}';
        }

        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );

        if ($encoded === false) {
            throw new RuntimeException('Evidence value cannot be encoded as deterministic JSON');
        }

        return $encoded;
    }
}