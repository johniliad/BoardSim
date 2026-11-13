<?php

declare(strict_types=1);

namespace BoardSim\Domain\Run;

use InvalidArgumentException;

/**
 * The deterministic 12-state lifecycle from 01_ARCHITECTURE.md:
 *
 *   CREATED -> PROFILE_LOCKED -> STAGE_1_OPEN -> STAGE_1_LOCKED -> ... ->
 *   BOARD_OPEN -> BOARD_LOCKED -> SCORED -> FINALIZED
 *
 * Transitions are forward-only. There is no transition back, and no state may be
 * skipped, so a late edit is impossible by construction rather than by policy.
 */
enum RunState: string
{
    case Created = 'CREATED';
    case ProfileLocked = 'PROFILE_LOCKED';
    case Stage1Open = 'STAGE_1_OPEN';
    case Stage1Locked = 'STAGE_1_LOCKED';
    case Stage2Open = 'STAGE_2_OPEN';
    case Stage2Locked = 'STAGE_2_LOCKED';
    case Stage3Open = 'STAGE_3_OPEN';
    case Stage3Locked = 'STAGE_3_LOCKED';
    case BoardOpen = 'BOARD_OPEN';
    case BoardLocked = 'BOARD_LOCKED';
    case Scored = 'SCORED';
    case Finalized = 'FINALIZED';

    /**
     * @return list<self>
     */
    public static function order(): array
    {
        return [
            self::Created,
            self::ProfileLocked,
            self::Stage1Open,
            self::Stage1Locked,
            self::Stage2Open,
            self::Stage2Locked,
            self::Stage3Open,
            self::Stage3Locked,
            self::BoardOpen,
            self::BoardLocked,
            self::Scored,
            self::Finalized,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::order());
    }

    public static function openFor(string $stageId): self
    {
        return match ($stageId) {
            'S1' => self::Stage1Open,
            'S2' => self::Stage2Open,
            'S3' => self::Stage3Open,
            default => throw new InvalidArgumentException("Unknown stage '{$stageId}'"),
        };
    }

    public static function lockedFor(string $stageId): self
    {
        return match ($stageId) {
            'S1' => self::Stage1Locked,
            'S2' => self::Stage2Locked,
            'S3' => self::Stage3Locked,
            default => throw new InvalidArgumentException("Unknown stage '{$stageId}'"),
        };
    }

    public function advance(): self
    {
        $order = self::order();
        $index = array_search($this, $order, true);

        if ($index === false || $index === count($order) - 1) {
            throw new InvalidArgumentException("Cannot advance from {$this->value}");
        }

        return $order[$index + 1];
    }

    public function isTerminal(): bool
    {
        return $this === self::Finalized;
    }

    public function hasLegalClock(): bool
    {
        return $this === self::BoardOpen || $this === self::BoardLocked
            || $this === self::Scored || $this === self::Finalized;
    }
}
