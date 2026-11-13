<?php

declare(strict_types=1);

namespace BoardSim\Domain\Regulation;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Statutory clock arithmetic.
 *
 * Rules enforced here (04_REGULATORY_REGISTER.md, 07_ACCEPTANCE_TESTS.md):
 *   - GDPR Art. 33 and NIS2 Art. 23 anchor on awareness_time, not on the moment
 *     of the original technical anomaly;
 *   - DORA 2025/301 Art. 5 needs BOTH classification_time and awareness_time and
 *     distinguishes the late-classification case;
 *   - a timer anchored on a filing event that has not occurred is a PROJECTION,
 *     never an overdue deadline;
 *   - "without undue delay" is not a countdown and renders qualitatively;
 *   - a TRIGGERED instrument whose anchor timestamp is missing fails closed.
 */
final class ClockEngine
{
    private const UTC = 'UTC';

    /**
     * @param list<array<string, mixed>> $timers
     *
     * @return list<array<string, mixed>>
     */
    public function compute(array $timers, FactSet $facts): array
    {
        $computed = [];
        $byId = [];

        foreach ($timers as $timer) {
            $result = $this->computeOne($timer, $facts, $byId);
            $computed[] = $result;
            $byId[(string) $result['id']] = $result;
        }

        return $computed;
    }

    /**
     * @param array<string, mixed> $timer
     * @param array<string, array<string, mixed>> $byId
     *
     * @return array<string, mixed>
     */
    private function computeOne(array $timer, FactSet $facts, array $byId): array
    {
        $id = (string) ($timer['id'] ?? '');
        $label = (string) ($timer['label'] ?? '');
        $windowType = (string) ($timer['window_type'] ?? 'FIXED_OFFSET');

        $result = [
            'id' => $id,
            'label' => $label,
            'window_type' => $windowType,
            'deadline' => null,
            'projection' => false,
            'late_classification_special_rule' => false,
            'note' => null,
        ];

        if ($windowType === 'WITHOUT_UNDUE_DELAY') {
            $result['note'] = 'Qualitative obligation: without undue delay. No fixed period is asserted.';

            return $result;
        }

        $clockRule = (string) ($timer['clock_rule'] ?? '');

        if ($clockRule === 'DORA_ART5_INITIAL') {
            return $this->computeDoraInitial($result, $facts);
        }

        $anchorFact = $timer['anchor_fact'] ?? null;

        if (is_string($anchorFact) && $anchorFact !== '') {
            $base = $this->requireTimestamp($facts, $anchorFact, $id);
            $result['anchor'] = $anchorFact;

            return $this->finalise($result, $timer, $base);
        }

        $anchorEvent = (string) ($timer['anchor_event'] ?? '');
        if ($anchorEvent === '') {
            throw new RuntimeException("Timer {$id} declares neither anchor_fact, anchor_event nor clock_rule");
        }

        if ($facts->has($anchorEvent) && is_string($facts->raw($anchorEvent))) {
            $base = $this->parseTimestamp((string) $facts->raw($anchorEvent), $id);
            $result['anchor'] = $anchorEvent;

            return $this->finalise($result, $timer, $base);
        }

        $projectionFrom = (string) ($timer['projection_from_timer'] ?? '');
        $source = $byId[$projectionFrom] ?? null;

        if ($source === null || ($source['deadline'] ?? null) === null) {
            throw new RuntimeException(
                "Timer {$id} is anchored on event '{$anchorEvent}' which has not occurred, "
                . 'and no projection source is available'
            );
        }

        $base = $this->parseTimestamp((string) $source['deadline'], $id);
        $result['projection'] = true;
        $result['anchor'] = $anchorEvent;
        $result['note'] = sprintf(
            'Projected from the %s deadline because no %s event has been recorded. '
            . 'This is a simulation projection, not a statutory deadline on its own.',
            $projectionFrom,
            $anchorEvent
        );

        return $this->finalise($result, $timer, $base);
    }

    /**
     * DORA 2025/301 Art. 5: 4 hours from classification, capped at 24 hours from
     * awareness when classification happens inside that window; otherwise the
     * late-classification special rule applies.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function computeDoraInitial(array $result, FactSet $facts): array
    {
        $id = (string) $result['id'];
        $classification = $this->requireTimestamp($facts, 'classification_time', $id);
        $awareness = $this->requireTimestamp($facts, 'awareness_time', $id);

        $fourHours = $classification->modify('+4 hours');
        $cap = $awareness->modify('+24 hours');
        $late = ($classification->getTimestamp() - $awareness->getTimestamp()) > 86400;

        if ($late) {
            $deadline = $fourHours;
        } else {
            $deadline = $fourHours <= $cap ? $fourHours : $cap;
        }

        $result['deadline'] = $deadline->setTimezone(new DateTimeZone(self::UTC))->format('Y-m-d\TH:i:s\Z');
        $result['anchor'] = 'classification_time';
        $result['secondary_cap'] = $cap->setTimezone(new DateTimeZone(self::UTC))->format('Y-m-d\TH:i:s\Z');
        $result['late_classification_special_rule'] = $late;
        $result['note'] = $late
            ? 'Classification occurred more than 24 hours after awareness: the special rule applies '
                . '(4 hours from classification) and requires regulatory review.'
            : 'Initial notification: 4 hours from classification, capped at 24 hours from awareness.';

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $timer
     *
     * @return array<string, mixed>
     */
    private function finalise(array $result, array $timer, DateTimeImmutable $base): array
    {
        $calendar = $timer['calendar'] ?? null;
        $offsetHours = $timer['offset_hours'] ?? null;

        if (is_string($calendar) && $calendar !== '') {
            $deadline = $base->modify($calendar);
        } elseif (is_int($offsetHours)) {
            $deadline = $base->modify(sprintf('%+d hours', $offsetHours));
        } else {
            throw new RuntimeException(
                "Timer {$result['id']} declares neither offset_hours nor calendar"
            );
        }

        $result['deadline'] = $deadline->setTimezone(new DateTimeZone(self::UTC))->format('Y-m-d\TH:i:s\Z');

        return $result;
    }

    private function requireTimestamp(FactSet $facts, string $key, string $timerId): DateTimeImmutable
    {
        $value = $facts->raw($key);

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "TimestampMissing: timer {$timerId} is TRIGGERED but fact '{$key}' has no value"
            );
        }

        return $this->parseTimestamp($value, $timerId);
    }

    private function parseTimestamp(string $value, string $timerId): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            DateTimeImmutable::ATOM,
            $value,
            new DateTimeZone(self::UTC)
        );

        if ($parsed === false) {
            $fallback = date_create_immutable($value, new DateTimeZone(self::UTC));
            $parsed = $fallback === false ? false : $fallback;
        }

        if ($parsed === false) {
            throw new RuntimeException("InvalidFactPath: timer {$timerId} anchor '{$value}' is not ISO-8601");
        }

        return $parsed;
    }
}
