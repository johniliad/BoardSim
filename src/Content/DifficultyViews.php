<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * Read-only difficulty projections over the single canonical scenario graph.
 *
 * A difficulty never changes which stages exist or which options are offered; it
 * changes what is shown and which board fields are collected. Because the board
 * subset determines how many board rules can fire, difficulty genuinely affects
 * the attainable score — which is why it is pinned on the run and must segment
 * profile aggregation.
 */
final class DifficultyViews
{
    public const LEADERSHIP = 'leadership';
    public const EXECUTIVE = 'executive';
    public const BOARD_CRISIS = 'board_crisis';
    public const STAGES = ['S1', 'S2', 'S3'];

    /**
     * @param array<string, array<string, mixed>> $views
     */
    private function __construct(
        private readonly array $views,
        private readonly string $version,
        private readonly string $sha256,
    ) {
    }

    /**
     * Conservative fallback used when a deployment has not loaded the overlay.
     *
     * It fails closed for `executive` rather than silently treating it as
     * `leadership`, because a difficulty that claims to be abbreviated must
     * actually be abbreviated.
     */
    public static function strictFallback(): self
    {
        $view = static fn (
            string $key,
            string $label,
            bool $available,
            array $fields,
            ?string $reason = null
        ): array => [
            'key' => $key,
            'label' => $label,
            'available' => $available,
            'time_estimate' => '',
            'description' => '',
            'stages' => self::STAGES,
            'primary_stage' => null,
            'board_fields' => $fields,
            'show_effects' => $available,
            'show_stakeholder_pressure' => $available,
            'challenge_cards' => 0,
            'unavailable_reason' => $reason,
        ];

        return new self(
            [
                self::LEADERSHIP => $view(
                    self::LEADERSHIP,
                    'Leadership',
                    true,
                    ['situation', 'impact', 'uncertainty', 'action', 'funding', 'residual_risk', 'ask']
                ),
                self::EXECUTIVE => $view(
                    self::EXECUTIVE,
                    'Executive',
                    false,
                    [],
                    'The difficulty_views overlay is not loaded on this deployment.'
                ),
                self::BOARD_CRISIS => $view(
                    self::BOARD_CRISIS,
                    'Board crisis',
                    false,
                    [],
                    'Board crisis is a reserved extension and has not been authored in v1.0.'
                ),
            ],
            '0.0.0-fallback',
            hash('sha256', 'difficulty-views-fallback')
        );
    }

    public static function load(string $projectRoot): self
    {
        $path = $projectRoot . '/content/overlays/difficulty_views.yaml';

        if (!is_file($path)) {
            throw new RuntimeException('Missing content/overlays/difficulty_views.yaml');
        }

        $doc = Yaml::parse((string) file_get_contents($path));
        $raw = $doc['views'] ?? null;

        if (!is_array($raw) || $raw === []) {
            throw new RuntimeException('difficulty_views.yaml declares no views');
        }

        $provenance = $doc['provenance'] ?? null;

        if (!is_array($provenance)
            || !is_bool($provenance['counsel_reviewed'] ?? null)
            || !is_bool($provenance['psychometrically_calibrated'] ?? null)
            || (string) ($provenance['source_kind'] ?? '') !== 'SIMULATION_ASSUMPTION'
        ) {
            throw new RuntimeException('difficulty_views.yaml must declare SIMULATION_ASSUMPTION provenance');
        }

        $views = [];

        foreach ([self::LEADERSHIP, self::EXECUTIVE, self::BOARD_CRISIS] as $key) {
            $definition = $raw[$key] ?? null;

            if (!is_array($definition)) {
                throw new RuntimeException("difficulty_views.yaml is missing the '{$key}' view");
            }

            if (!is_bool($definition['available'] ?? null)) {
                throw new RuntimeException("View '{$key}' must declare a boolean available");
            }

            $available = $definition['available'];
            $stages = array_values(array_map('strval', (array) ($definition['stages'] ?? [])));
            $fields = array_values(array_map('strval', (array) ($definition['board_fields'] ?? [])));
            $primary = $definition['primary_stage'] ?? null;
            $reason = $definition['unavailable_reason'] ?? null;

            if ($available && $stages !== self::STAGES) {
                throw new RuntimeException(
                    "View '{$key}' must run the canonical three stages; found: " . implode(',', $stages)
                );
            }

            if ($available && $fields === []) {
                throw new RuntimeException("Available view '{$key}' must declare board_fields");
            }

            if ($primary !== null && !in_array((string) $primary, self::STAGES, true)) {
                throw new RuntimeException("View '{$key}' has an invalid primary_stage");
            }

            if (!$available && (string) ($reason ?? '') === '') {
                throw new RuntimeException("Unavailable view '{$key}' must explain why");
            }

            $views[$key] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'available' => $available,
                'time_estimate' => (string) ($definition['time_estimate'] ?? ''),
                'description' => (string) ($definition['description'] ?? ''),
                'stages' => $stages,
                'primary_stage' => $primary === null ? null : (string) $primary,
                'board_fields' => $fields,
                'show_effects' => (bool) ($definition['show_effects'] ?? false),
                'show_stakeholder_pressure' => (bool) ($definition['show_stakeholder_pressure'] ?? false),
                'challenge_cards' => (int) ($definition['challenge_cards'] ?? 0),
                'unavailable_reason' => $reason === null ? null : (string) $reason,
            ];
        }

        // F-5: board_crisis is reserved, and the runtime rejection must come from
        // this policy so the API and the interface copy cannot drift apart.
        if ($views[self::BOARD_CRISIS]['available']) {
            throw new RuntimeException('board_crisis must remain unavailable until it is authored');
        }

        // The executive view exists to be abbreviated; assert that it really is.
        $leadershipCount = count($views[self::LEADERSHIP]['board_fields']);
        $executiveCount = count($views[self::EXECUTIVE]['board_fields']);

        if ($views[self::EXECUTIVE]['available'] && $executiveCount >= $leadershipCount) {
            throw new RuntimeException(
                "The executive view must collect fewer board fields than leadership; found {$executiveCount} of {$leadershipCount}"
            );
        }

        return new self(
            $views,
            (string) ($doc['version'] ?? '0.0.0'),
            CanonicalJson::sha256(['version' => (string) ($doc['version'] ?? ''), 'views' => $views])
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->views;
    }

    /**
     * @return list<string>
     */
    public function availableKeys(): array
    {
        $keys = [];

        foreach ($this->views as $key => $view) {
            if ($view['available'] === true) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        if (!isset($this->views[$key])) {
            throw new RuntimeException("UnknownDifficulty: '{$key}'");
        }

        return $this->views[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->views[$key]);
    }

    /**
     * Filter the full rubric to the fields this difficulty collects, preserving
     * the rubric's own order.
     *
     * @param array<string, array<string, mixed>> $allFields
     *
     * @return array<string, array<string, mixed>>
     */
    public function filterFields(string $difficulty, array $allFields): array
    {
        $allowed = $this->get($difficulty)['board_fields'];
        $filtered = [];

        foreach ($allFields as $key => $definition) {
            if (in_array((string) $key, $allowed, true)) {
                $filtered[(string) $key] = $definition;
            }
        }

        if (count($filtered) !== count($allowed)) {
            $missing = array_diff($allowed, array_keys($filtered));

            throw new RuntimeException(
                "Difficulty '{$difficulty}' references board fields absent from the rubric: " . implode(',', $missing)
            );
        }

        return $filtered;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }
}
