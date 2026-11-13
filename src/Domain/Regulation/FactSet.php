<?php

declare(strict_types=1);

namespace BoardSim\Domain\Regulation;

use InvalidArgumentException;

/**
 * The set of authored regulatory facts visible at one stage.
 *
 * Resolution rule (F-2 specification §5.1):
 *   1. keep only facts whose visible_from_stage <= current_stage;
 *   2. group by predicate key and take the highest visible stage, so a Stage 2
 *      assertion deterministically overrides the Stage 1 placeholder;
 *   3. a declared-but-unasserted key resolves to UNKNOWN, never to false;
 *   4. an undeclared key is a load-time error (enforced by the vocabulary check),
 *      so a typo can never silently degrade to UNKNOWN.
 */
final class FactSet
{
    public const STAGE_ORDER = ['S1' => 1, 'S2' => 2, 'S3' => 3];

    /**
     * @param array<string, array{value: mixed, fact_id: string, stage: string, source_kind: string}> $resolved
     */
    private function __construct(
        private readonly array $resolved,
        private readonly string $stage
    ) {
    }

    /**
     * @param list<array<string, mixed>> $authored
     */
    public static function fromAuthored(array $authored, string $stage): self
    {
        if (!isset(self::STAGE_ORDER[$stage])) {
            throw new InvalidArgumentException("Unknown stage '{$stage}'");
        }

        $resolved = [];
        $current = self::STAGE_ORDER[$stage];

        foreach ($authored as $fact) {
            $key = (string) ($fact['key'] ?? '');
            $factStage = (string) ($fact['visible_from_stage'] ?? '');
            $factId = (string) ($fact['id'] ?? '');

            if (!isset(self::STAGE_ORDER[$factStage])) {
                throw new InvalidArgumentException("Fact {$factId} has unknown stage '{$factStage}'");
            }

            if (self::STAGE_ORDER[$factStage] > $current) {
                continue;
            }

            $existing = $resolved[$key] ?? null;

            if ($existing !== null) {
                $existingRank = self::STAGE_ORDER[$existing['stage']];
                $newRank = self::STAGE_ORDER[$factStage];

                if ($newRank < $existingRank) {
                    continue;
                }

                if ($newRank === $existingRank) {
                    throw new InvalidArgumentException(
                        "Two facts assert '{$key}' at stage {$factStage} ({$existing['fact_id']}, {$factId})"
                    );
                }
            }

            $resolved[$key] = [
                'value' => $fact['value'] ?? null,
                'fact_id' => $factId,
                'stage' => $factStage,
                'source_kind' => (string) ($fact['source_kind'] ?? ''),
            ];
        }

        return new self($resolved, $stage);
    }

    /**
     * A declared-but-unasserted predicate is UNKNOWN (never false).
     */
    public function tri(string $key): TriBool
    {
        if (!array_key_exists($key, $this->resolved)) {
            return TriBool::Unknown;
        }

        return TriBool::fromAuthored($this->resolved[$key]['value'], $key);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->resolved);
    }

    public function raw(string $key): mixed
    {
        return $this->resolved[$key]['value'] ?? null;
    }

    public function stage(): string
    {
        return $this->stage;
    }

    /**
     * Fact identifiers visible at this stage, for the append-only Event snapshot.
     *
     * @return list<string>
     */
    public function visibleFactIds(): array
    {
        $ids = array_map(
            static fn (array $fact): string => $fact['fact_id'],
            array_values($this->resolved)
        );

        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, array{value: mixed, fact_id: string, stage: string, source_kind: string}>
     */
    public function all(): array
    {
        return $this->resolved;
    }
}
