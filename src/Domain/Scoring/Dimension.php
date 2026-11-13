<?php

declare(strict_types=1);

namespace BoardSim\Domain\Scoring;

/**
 * The ten measured dimensions from 00_PRODUCT_AND_SCOPE.md.
 *
 * This is a closed set: 02_SCORING_MODEL.md fixes the codes and the weights, and
 * anything else in a rule delta is a hard load error rather than an ignored key.
 */
enum Dimension: string
{
    case SJ = 'SJ';
    case BI = 'BI';
    case RJ = 'RJ';
    case DQ = 'DQ';
    case LE = 'LE';
    case CO = 'CO';
    case RG = 'RG';
    case FJ = 'FJ';
    case RE = 'RE';
    case SM = 'SM';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Canonical (lexicographic) order, used to make traces independent of the
     * order in which keys happen to appear in an authored file.
     */
    public static function canonicalOrder(): array
    {
        $codes = self::codes();
        sort($codes, SORT_STRING);

        return $codes;
    }

    public static function isValid(string $code): bool
    {
        return self::tryFrom($code) !== null;
    }
}
