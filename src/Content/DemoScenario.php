<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * The public demonstration challenge.
 *
 * Deliberately separate from `PinnedScenarios`: the demo is never scored and never
 * persisted, so folding it into the scored catalogue would move `content_version`
 * and force a re-baseline of all 2,268 enumeration runs for no benefit. It carries
 * its own version and hash instead.
 */
final class DemoScenario
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly array $data,
        private readonly string $version,
        private readonly string $sha256,
    ) {
    }

    public static function unavailable(): self
    {
        return new self([], '0.0.0', str_repeat('0', 64));
    }

    public static function load(string $projectRoot): self
    {
        $path = rtrim($projectRoot, '/\\') . '/content/demo/DEMO-001.md';

        if (!is_file($path)) {
            return self::unavailable();
        }

        $raw = file_get_contents($path);
        $raw = $raw === false ? '' : $raw;

        if (preg_match('/```yaml\r?\n(.*?)```/s', $raw, $match) !== 1) {
            throw new RuntimeException('DEMO-001.md has no yaml block');
        }

        $data = Yaml::parse($match[1]);
        $id = (string) ($data['scenario_id'] ?? '');

        if ($id !== 'DEMO-001') {
            throw new RuntimeException("DEMO-001.md declares scenario_id '{$id}'");
        }

        // A demo that could be scored or stored would defeat its purpose.
        if (($data['scored'] ?? null) !== false || ($data['persisted'] ?? null) !== false) {
            throw new RuntimeException('DEMO-001 must declare scored: false and persisted: false');
        }

        $stages = $data['stages'] ?? null;

        if (!is_array($stages) || $stages === []) {
            throw new RuntimeException('DEMO-001 has no stages');
        }

        foreach ($stages as $stage) {
            $stageId = (string) ($stage['id'] ?? '');
            $options = $stage['options'] ?? null;

            if ($stageId === '' || !is_array($options) || count($options) !== 3) {
                throw new RuntimeException("DEMO-001 stage '{$stageId}' must offer exactly three options");
            }

            foreach ($options as $option) {
                $optionId = (string) ($option['id'] ?? '');
                $rules = $option['rules'] ?? null;

                if ($optionId === '' || !is_array($rules) || $rules === []) {
                    throw new RuntimeException("DEMO-001 {$stageId} option '{$optionId}' has no rules");
                }
            }
        }

        return new self($data, (string) ($data['version'] ?? ''), hash('sha256', $match[1]));
    }

    public function available(): bool
    {
        return $this->data !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
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
