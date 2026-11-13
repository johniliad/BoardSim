<?php

declare(strict_types=1);

namespace BoardSim\Domain\Regulation;

use InvalidArgumentException;

/**
 * Kleene three-valued logic value.
 *
 * 01_ARCHITECTURE.md: "`unknown` must remain distinct from `false`." A strict
 * enum is used deliberately: PHP treats the string "unknown" as truthy, so any
 * loose comparison would silently convert an unresolved legal question into a
 * permissive answer.
 */
enum TriBool: string
{
    case Yes = 'true';
    case No = 'false';
    case Unknown = 'unknown';

    /**
     * @param mixed $value A YAML boolean or the literal string "unknown"
     */
    public static function fromAuthored(mixed $value, string $context): self
    {
        if (is_bool($value)) {
            return $value ? self::Yes : self::No;
        }

        if (is_string($value) && strtolower(trim($value)) === 'unknown') {
            return self::Unknown;
        }

        throw new InvalidArgumentException(
            sprintf('Predicate %s must be true, false or "unknown"', $context)
        );
    }

    public function negate(): self
    {
        return match ($this) {
            self::Yes => self::No,
            self::No => self::Yes,
            self::Unknown => self::Unknown,
        };
    }

    /**
     * Kleene conjunction: false dominates, then unknown, then true.
     */
    public static function all(self ...$operands): self
    {
        $sawUnknown = false;

        foreach ($operands as $operand) {
            if ($operand === self::No) {
                return self::No;
            }

            if ($operand === self::Unknown) {
                $sawUnknown = true;
            }
        }

        return $sawUnknown ? self::Unknown : self::Yes;
    }

    /**
     * Kleene disjunction: true dominates, then unknown, then false.
     */
    public static function any(self ...$operands): self
    {
        $sawUnknown = false;

        foreach ($operands as $operand) {
            if ($operand === self::Yes) {
                return self::Yes;
            }

            if ($operand === self::Unknown) {
                $sawUnknown = true;
            }
        }

        return $sawUnknown ? self::Unknown : self::No;
    }

    public function isTrue(): bool
    {
        return $this === self::Yes;
    }

    public function isUnknown(): bool
    {
        return $this === self::Unknown;
    }
}
