<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * Reads the SHA-256 pinned scenario markdown files.
 *
 * The base files are immutable (08_CONTENT_GOVERNANCE.md). This reader extracts
 * only the normative YAML fenced block; all surrounding markdown is treated as
 * untrusted prose and is never executed.
 */
final class PinnedScenarios
{
    public function __construct(
        private readonly string $root,
        private readonly ?string $contentVersion = null,
    ) {
    }

    /** @var array<string, mixed>|null */
    private ?array $overlay = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadAll(): array
    {
        $scenarios = $this->loadPinned();
        $this->overlay = $this->loadRemapOverlay();

        if ($this->overlay !== null && $this->shouldApplyOverlay()) {
            $scenarios = $this->applyRemaps($scenarios, $this->overlay);
        }

        return $scenarios;
    }

    /**
     * A historical run pinned a specific content version, so requesting 1.0.0
     * must return the pinned option graph untouched. Without this, replaying a
     * 1.0.0 run after a content remap would silently score differently.
     */
    private function shouldApplyOverlay(): bool
    {
        if ($this->contentVersion === null) {
            return true;
        }

        return (string) ($this->overlay['content_version'] ?? '') === $this->contentVersion;
    }

    /**
     * Content version in force: the pinned 1.0.0 unless a remap overlay is present
     * and applies to the requested version.
     */
    public function contentVersion(): string
    {
        if ($this->overlay === null) {
            $this->overlay = $this->loadRemapOverlay();
        }

        if ($this->contentVersion !== null) {
            return $this->contentVersion;
        }

        return $this->overlay['content_version'] ?? '1.0.0';
    }

    /**
     * Hash covering the pinned scenario versions plus every remap in force, so a
     * run can pin exactly which option-to-rule graph produced it.
     */
    public function contentSha256(): string
    {
        if ($this->overlay === null) {
            $this->overlay = $this->loadRemapOverlay();
        }

        $versions = [];

        foreach ($this->loadPinned() as $scenarioId => $scenario) {
            $versions[$scenarioId] = $scenario['version'];
        }

        // The hash must reflect the *resolved* version, so a 1.0.0 pin hashes the
        // pinned option graph and not the remapped one.
        return CanonicalJson::sha256([
            'content_version' => $this->contentVersion(),
            'scenario_versions' => $versions,
            'option_remaps' => $this->shouldApplyOverlay() ? ($this->overlay['option_remaps'] ?? []) : [],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRemapOverlay(): ?array
    {
        $path = $this->root . '/content/overlays/scenario_option_remap.yaml';

        if (!is_file($path)) {
            return null;
        }

        $doc = Yaml::parse((string) file_get_contents($path));
        $version = (string) ($doc['content_version'] ?? '');

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new RuntimeException('scenario_option_remap.yaml needs a semver content_version');
        }

        $remaps = $doc['option_remaps'] ?? [];

        if (!is_array($remaps)) {
            throw new RuntimeException('option_remaps must be a mapping');
        }

        return ['content_version' => $version, 'option_remaps' => $remaps];
    }

    /**
     * @param array<string, array<string, mixed>> $scenarios
     * @param array<string, mixed> $overlay
     *
     * @return array<string, array<string, mixed>>
     */
    private function applyRemaps(array $scenarios, array $overlay): array
    {
        foreach ($overlay['option_remaps'] as $scenarioId => $stages) {
            $scenarioId = (string) $scenarioId;

            if (!isset($scenarios[$scenarioId])) {
                throw new RuntimeException("Remap targets unknown scenario '{$scenarioId}'");
            }

            foreach ((array) $stages as $stageId => $options) {
                foreach ((array) $options as $optionId => $remap) {
                    $applied = false;

                    foreach ((array) ($scenarios[$scenarioId]['stages'] ?? []) as $stageIndex => $stage) {
                        if ((string) ($stage['id'] ?? '') !== (string) $stageId) {
                            continue;
                        }

                        foreach ((array) ($stage['options'] ?? []) as $optionIndex => $option) {
                            if ((string) ($option['id'] ?? '') !== (string) $optionId) {
                                continue;
                            }

                            $this->assertRemapIsSound($remap, $option, "{$scenarioId}/{$stageId}/{$optionId}");

                            $scenarios[$scenarioId]['stages'][$stageIndex]['options'][$optionIndex]['rules']
                                = array_values(array_map('strval', $remap['to_rules']));
                            $scenarios[$scenarioId]['stages'][$stageIndex]['options'][$optionIndex]['remapped_from']
                                = array_values(array_map('strval', $remap['from_rules']));
                            $applied = true;
                        }
                    }

                    if (!$applied) {
                        throw new RuntimeException(
                            "Remap {$scenarioId}/{$stageId}/{$optionId} did not match any pinned option"
                        );
                    }
                }
            }
        }

        return $scenarios;
    }

    /**
     * @param array<string, mixed> $remap
     * @param array<string, mixed> $option
     */
    private function assertRemapIsSound(array $remap, array $option, string $context): void
    {
        $from = $remap['from_rules'] ?? null;
        $to = $remap['to_rules'] ?? null;

        if (!is_array($from) || !is_array($to) || $to === []) {
            throw new RuntimeException("Remap {$context} needs from_rules and a non-empty to_rules");
        }

        $pinnedRules = array_values(array_map('strval', (array) ($option['rules'] ?? [])));
        $claimed = array_values(array_map('strval', $from));

        sort($pinnedRules);
        sort($claimed);

        if ($pinnedRules !== $claimed) {
            throw new RuntimeException(
                "Remap {$context} claims rules that do not match the pinned option; refusing to override "
                . 'a binding it has not verified'
            );
        }
    }
    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadPinned(): array
    {
        $files = glob($this->root . '/scenarios/BS-*.md') ?: [];
        sort($files);

        if ($files === []) {
            throw new RuntimeException('No pinned scenario files found');
        }

        $scenarios = [];

        foreach ($files as $file) {
            $markdown = (string) file_get_contents($file);

            if (preg_match('/```yaml\n(.*?)\n```/s', $markdown, $match) !== 1) {
                throw new RuntimeException('No normative YAML block in ' . basename($file));
            }

            $data = Yaml::parse($match[1]);
            $id = (string) ($data['scenario_id'] ?? '');

            if ($id === '') {
                throw new RuntimeException('Scenario block without scenario_id in ' . basename($file));
            }

            if (isset($scenarios[$id])) {
                throw new RuntimeException("Duplicate scenario id {$id}");
            }

            $scenarios[$id] = [
                'scenario_id' => $id,
                'version' => (string) ($data['version'] ?? ''),
                'scenario_name' => (string) ($data['scenario_name'] ?? ''),
                'industry' => (string) ($data['industry'] ?? ''),
                'track' => (string) ($data['track'] ?? ''),
                'difficulty' => (string) ($data['difficulty'] ?? ''),
                'regulatory_refs' => is_array($data['regulatory_refs'] ?? null)
                    ? array_values(array_map('strval', $data['regulatory_refs']))
                    : [],
                'rule_guards' => is_array($data['rule_guards'] ?? null) ? $data['rule_guards'] : [],
                'stages' => is_array($data['stages'] ?? null) ? $data['stages'] : [],
                'board' => is_array($data['board'] ?? null) ? $data['board'] : [],
                'stakeholder_pressure' => (string) ($data['stakeholder_pressure'] ?? ''),
                'starting_briefing' => (string) ($data['starting_briefing'] ?? ''),
                'source_file' => 'scenarios/' . basename($file),
            ];
        }

        return $scenarios;
    }
}
