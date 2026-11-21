<?php

declare(strict_types=1);

namespace BoardSim\Domain\Scoring;

/**
 * Compares persisted and reconstructed dimension maps by their closed-set meaning.
 *
 * MySQL JSON objects do not promise to preserve PHP insertion order. Dimension
 * verification must therefore compare the ten named integer values, not PHP array
 * order, while still rejecting missing, unexpected or incorrectly typed values.
 */
final class DimensionComparison
{
    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $reconstructed
     *
     * @return array{
     *   matches: bool,
     *   missing_from_stored: list<string>,
     *   missing_from_reconstructed: list<string>,
     *   unexpected_in_stored: list<string>,
     *   unexpected_in_reconstructed: list<string>,
     *   invalid_in_stored: list<string>,
     *   invalid_in_reconstructed: list<string>,
     *   mismatched_dimensions: list<string>
     * }
     */
    public static function compare(array $stored, array $reconstructed): array
    {
        $expected = Dimension::canonicalOrder();
        $storedKeys = array_map('strval', array_keys($stored));
        $reconstructedKeys = array_map('strval', array_keys($reconstructed));

        $missingFromStored = array_values(array_diff($expected, $storedKeys));
        $missingFromReconstructed = array_values(array_diff($expected, $reconstructedKeys));
        $unexpectedInStored = array_values(array_diff($storedKeys, $expected));
        $unexpectedInReconstructed = array_values(array_diff($reconstructedKeys, $expected));
        $invalidInStored = [];
        $invalidInReconstructed = [];
        $mismatched = [];

        foreach ($expected as $code) {
            if (array_key_exists($code, $stored) && !is_int($stored[$code])) {
                $invalidInStored[] = $code;
            }

            if (array_key_exists($code, $reconstructed) && !is_int($reconstructed[$code])) {
                $invalidInReconstructed[] = $code;
            }

            if (is_int($stored[$code] ?? null)
                && is_int($reconstructed[$code] ?? null)
                && $stored[$code] !== $reconstructed[$code]
            ) {
                $mismatched[] = $code;
            }
        }

        $matches = $missingFromStored === []
            && $missingFromReconstructed === []
            && $unexpectedInStored === []
            && $unexpectedInReconstructed === []
            && $invalidInStored === []
            && $invalidInReconstructed === []
            && $mismatched === [];

        return [
            'matches' => $matches,
            'missing_from_stored' => $missingFromStored,
            'missing_from_reconstructed' => $missingFromReconstructed,
            'unexpected_in_stored' => $unexpectedInStored,
            'unexpected_in_reconstructed' => $unexpectedInReconstructed,
            'invalid_in_stored' => $invalidInStored,
            'invalid_in_reconstructed' => $invalidInReconstructed,
            'mismatched_dimensions' => $mismatched,
        ];
    }
}