<?php

declare(strict_types=1);

namespace BoardSim\Content;

use BoardSim\Domain\Regulation\RegulatoryStatus;
use RuntimeException;

/**
 * The in-product glossary, derived entirely from artefacts that already exist.
 *
 * Nothing here is authored twice. The mapping is:
 *
 *   dimensions   the table in requirements/02_SCORING_MODEL.md (name, code,
 *                weight, one-line meaning), cross-checked against
 *                engine/CONFIG.md's weights_percent so the two pinned sources
 *                cannot silently disagree
 *   rules        Ruleset::all() — id, title, rationale and the dimensions its
 *                deltas actually move
 *   instruments  the regulatory registry — id, title, nature, tier, source URL
 *   settings     difficulty_views.yaml, risk_appetite_delta, the persona list
 *                and the score ladder, all as structured facts
 *   statuses     RegulatoryStatus cases; the label is the code read as English
 *
 * Why derived rather than an authored overlay: a second place holding the same
 * facts is a second place to drift. A glossary that disagrees with the policy is
 * worse than no glossary, because it teaches the scoring system wrongly.
 *
 * The payload is a *view*, not a pinned artefact: it carries its own
 * glossary_sha256 over these values and is never folded into policy_sha256,
 * regulatory_bundle_sha256 or content_sha256. Folding it in would invalidate the
 * pins of every run already scored and force a re-score report for help text.
 */
final class Glossary
{
    private const SCORING_MODEL = '/requirements/02_SCORING_MODEL.md';

    /** The ten dimensions are fixed by 02_SCORING_MODEL.md. */
    private const EXPECTED_DIMENSIONS = 10;

    /**
     * @param array<string, mixed> $policy     ScoringPolicyLoader::load()
     * @param array<string, mixed> $regulatory OverlayLoader::load()
     *
     * @return array<string, mixed>
     */
    public static function build(
        string $projectRoot,
        array $policy,
        array $regulatory,
        DifficultyViews $views
    ): array {
        $modelPath = rtrim($projectRoot, '/\\') . self::SCORING_MODEL;

        if (!is_file($modelPath)) {
            throw new RuntimeException('Glossary requires ' . self::SCORING_MODEL);
        }

        $model = self::dimensionsFromScoringModel((string) file_get_contents($modelPath));
        $weights = $policy['weights'];
        $nist = NistFunctions::load($projectRoot);

        self::assertDimensionsAgree($model, $weights);

        // Rules first, so each dimension can list the rules that move it.
        $rules = [];
        $rulesByDimension = array_fill_keys(array_keys($model), []);

        foreach ($policy['ruleset']->all() as $rule) {
            $dimensions = [];

            foreach ($rule->deltas as $code => $delta) {
                if ((int) $delta !== 0) {
                    $dimensions[] = (string) $code;
                }
            }

            sort($dimensions);

            if (!isset($nist['rules'][$rule->id])) {
                throw new RuntimeException(
                    'The NIST mapping does not cover ' . $rule->id
                    . '; add it to content/overlays/rule_nist_functions.yaml'
                );
            }

            $rules[] = [
                'id' => $rule->id,
                'title' => $rule->title,
                'rationale' => $rule->rationale,
                'dimensions' => $dimensions,
                'nist_function' => $nist['rules'][$rule->id],
                'board_rule' => $rule->isBoardRule(),
            ];

            foreach ($dimensions as $code) {
                if (isset($rulesByDimension[$code])) {
                    $rulesByDimension[$code][] = $rule->id;
                }
            }
        }

        usort($rules, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        foreach ($rulesByDimension as &$ids) {
            sort($ids);
        }

        unset($ids);

        $dimensions = [];

        // CONFIG.md's order (SJ first) is the reading order of the scoring model,
        // so it is preserved rather than sorted alphabetically.
        foreach (array_keys($weights) as $code) {
            $code = (string) $code;

            $dimensions[] = [
                'code' => $code,
                'name' => $model[$code]['name'],
                'weight' => (int) $weights[$code],
                'definition' => $model[$code]['definition'],
                'rules' => $rulesByDimension[$code] ?? [],
            ];
        }

        $instruments = [];

        foreach ($regulatory['instruments'] as $instrument) {
            $instruments[] = [
                'id' => $instrument->id,
                'title' => $instrument->title,
                'nature' => $instrument->nature,
                'verification_tier' => $instrument->verificationTier,
                'reporting_duty' => $instrument->reportingDuty,
                'counsel_signed_off' => $instrument->counselSignedOff,
                'source_url' => $instrument->sourceUrl,
            ];
        }

        usort($instruments, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        $settings = [];

        foreach ($views->all() as $key => $view) {
            $settings[] = [
                'key' => 'difficulty.' . $key,
                'group' => 'difficulty',
                'label' => (string) $view['label'],
                'time_estimate' => (string) $view['time_estimate'],
                'board_fields' => count($view['board_fields']),
                'available' => (bool) $view['available'],
                'description' => (string) $view['description'],
                'unavailable_reason' => $view['unavailable_reason'] ?? null,
            ];
        }

        foreach ($policy['appetite'] as $key => $modifiers) {
            $settings[] = [
                'key' => 'appetite.' . $key,
                'group' => 'appetite',
                'label' => ucfirst((string) $key),
                'modifiers' => $modifiers,
            ];
        }

        foreach ($policy['personas'] as $persona) {
            $settings[] = [
                'key' => 'persona.' . $persona,
                'group' => 'persona',
                'label' => ucfirst(str_replace('_', ' ', (string) $persona)),
                'display_only' => true,
            ];
        }

        foreach ($policy['ladder'] as $band) {
            $settings[] = [
                'key' => 'band.' . $band['min'],
                'group' => 'band',
                'label' => (string) $band['label'],
                'min' => (int) $band['min'],
            ];
        }

        $statuses = [];

        foreach (RegulatoryStatus::cases() as $case) {
            $statuses[] = [
                'code' => $case->value,
                'label' => self::humanise($case->value),
                // No machine-readable definition of each status exists in the
                // pinned sources, so none is invented here: the interface links
                // to the methodology instead.
                'meaning' => null,
            ];
        }

        $payload = [
            'glossary_version' => sprintf(
                'ruleset %s · regulatory %s · difficulty %s · nist %s',
                (string) $policy['ruleset_version'],
                (string) $regulatory['bundle']['regulatory_overlay_version'],
                $views->version(),
                $nist['version']
            ),
            'nist_functions' => $nist['functions'],
            'nist_functions_version' => $nist['version'],
            'dimensions' => $dimensions,
            'rules' => $rules,
            'instruments' => $instruments,
            'settings' => $settings,
            'statuses' => $statuses,
        ];

        return ['glossary_sha256' => CanonicalJson::sha256($payload)] + $payload;
    }

    /**
     * @return array<string, array{name: string, weight: int, definition: string}>
     */
    private static function dimensionsFromScoringModel(string $markdown): array
    {
        $dimensions = [];

        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            $matched = preg_match(
                '/^\|\s*([^|]+?)\s*\|\s*([A-Z]{2})\s*\|\s*(\d+)%\s*\|\s*([^|]+?)\s*\|\s*$/u',
                $line,
                $cells
            );

            if ($matched !== 1) {
                continue;
            }

            $dimensions[$cells[2]] = [
                'name' => $cells[1],
                'weight' => (int) $cells[3],
                'definition' => $cells[4],
            ];
        }

        if (count($dimensions) !== self::EXPECTED_DIMENSIONS) {
            throw new RuntimeException(sprintf(
                '%s should declare %d dimensions; parsed %d',
                self::SCORING_MODEL,
                self::EXPECTED_DIMENSIONS,
                count($dimensions)
            ));
        }

        return $dimensions;
    }

    /**
     * The document and the policy must agree, or the glossary would teach numbers
     * the scorer does not use.
     *
     * @param array<string, array{name: string, weight: int, definition: string}> $model
     * @param array<string, int> $weights
     */
    private static function assertDimensionsAgree(array $model, array $weights): void
    {
        $modelCodes = array_keys($model);
        $weightCodes = array_map('strval', array_keys($weights));
        sort($modelCodes);
        sort($weightCodes);

        if ($modelCodes !== $weightCodes) {
            throw new RuntimeException(
                self::SCORING_MODEL . ' and engine/CONFIG.md disagree on dimension codes: '
                . implode(',', $modelCodes) . ' vs ' . implode(',', $weightCodes)
            );
        }

        foreach ($model as $code => $definition) {
            if ((int) $weights[$code] !== $definition['weight']) {
                throw new RuntimeException(sprintf(
                    'Dimension %s is %d%% in %s but %d%% in engine/CONFIG.md',
                    $code,
                    $definition['weight'],
                    self::SCORING_MODEL,
                    (int) $weights[$code]
                ));
            }
        }
    }

    private static function humanise(string $code): string
    {
        return ucfirst(strtolower(str_replace('_', ' ', $code)));
    }
}
