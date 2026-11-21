<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

use DateTimeImmutable;

/**
 * Explicit bounds for every client-controlled identifier and collection.
 *
 * These checks live below the router as well as at the HTTP boundary so CLI
 * callers and future transports cannot bypass the database-shaped limits.
 */
final class InputValidator
{
    public const MAX_RUN_IDS = 20;
    public const MAX_BOARD_SELECTIONS = 10;

    public static function runId(mixed $value, string $name = 'run_id'): string
    {
        return self::matchingString(
            $value,
            $name,
            36,
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            'a UUID'
        );
    }

    public static function scenarioId(mixed $value): string
    {
        return self::matchingString($value, 'scenario_id', 10, '/^BS-[0-9]{3}$/', 'BS-NNN');
    }

    public static function stageId(mixed $value): string
    {
        return self::matchingString($value, 'stage_id', 2, '/^S[1-3]$/', 'S1, S2 or S3');
    }

    public static function choiceId(mixed $value): string
    {
        return self::matchingString($value, 'choice_id', 1, '/^[A-C]$/', 'A, B or C');
    }

    public static function idempotencyKey(mixed $value): string
    {
        return self::matchingString(
            $value,
            'idempotency_key',
            36,
            '/^[A-Za-z0-9._:-]+$/',
            '1-36 characters using A-Z, a-z, 0-9, dot, underscore, colon or hyphen'
        );
    }

    public static function difficulty(mixed $value, string $default = 'leadership'): string
    {
        return self::boundedString($value ?? $default, 'difficulty', 32);
    }

    public static function riskAppetite(mixed $value, string $default = 'balanced'): string
    {
        return self::boundedString($value ?? $default, 'risk_appetite', 16);
    }

    public static function boardPersona(mixed $value, string $default = 'balanced'): string
    {
        return self::boundedString($value ?? $default, 'board_persona', 32);
    }

    public static function simulationDate(mixed $value, string $default): string
    {
        $date = self::matchingString(
            $value ?? $default,
            'date_of_simulation',
            10,
            '/^\d{4}-\d{2}-\d{2}$/',
            'YYYY-MM-DD'
        );
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw DomainError::invalid('date_of_simulation must be a real calendar date in YYYY-MM-DD format');
        }

        return $date;
    }

    public static function reference(mixed $value): string
    {
        return self::matchingString(
            $value,
            'reference',
            64,
            '/^REG-[A-Z0-9-]+$/',
            'REG- followed by uppercase letters, numbers or hyphens'
        );
    }

    public static function shareToken(mixed $value): string
    {
        return self::matchingString($value, 'share token', 64, '/^[0-9a-f]{64}$/', '64 lowercase hexadecimal characters');
    }

    /**
     * @return list<string>
     */
    public static function runIdList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw DomainError::invalid('run_ids must be a JSON array');
        }

        if (count($value) > self::MAX_RUN_IDS) {
            throw DomainError::invalid('run_ids may contain at most ' . self::MAX_RUN_IDS . ' entries');
        }

        return array_map(
            static fn (mixed $runId): string => self::runId($runId, 'run_ids entry'),
            $value
        );
    }

    /**
     * @return array<string, string>
     */
    public static function boardSelections(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw DomainError::invalid('selections must be a JSON object');
        }

        if (count($value) > self::MAX_BOARD_SELECTIONS) {
            throw DomainError::invalid('selections may contain at most ' . self::MAX_BOARD_SELECTIONS . ' entries');
        }

        $validated = [];

        foreach ($value as $field => $option) {
            $field = self::matchingString(
                $field,
                'selection field',
                24,
                '/^[a-z][a-z0-9_]*$/',
                'a lowercase field identifier'
            );
            $validated[$field] = self::matchingString(
                $option,
                "selection '{$field}'",
                32,
                '/^[A-Z0-9-]+$/',
                'an uppercase option identifier'
            );
        }

        return $validated;
    }

    public static function boundedString(mixed $value, string $name, int $maxBytes): string
    {
        if (!is_string($value)) {
            throw DomainError::invalid("{$name} must be a string");
        }

        if ($value === '') {
            throw DomainError::invalid("{$name} is required");
        }

        if (strlen($value) > $maxBytes) {
            throw DomainError::invalid("{$name} must not exceed {$maxBytes} bytes");
        }

        return $value;
    }

    private static function matchingString(
        mixed $value,
        string $name,
        int $maxBytes,
        string $pattern,
        string $expected
    ): string {
        $string = self::boundedString($value, $name, $maxBytes);

        if (preg_match($pattern, $string) !== 1) {
            throw DomainError::invalid("{$name} must be {$expected}");
        }

        return $string;
    }
}