<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * Loads the authored NIST CSF 2.0 function mapping for every decision rule.
 *
 * Kept out of the regulatory bundle and out of the pinned rule catalogue on
 * purpose: it is a presentation aid, so it must be versioned and hashable on its
 * own without moving any run's pins. `GET /v1/glossary` reports
 * `nist_functions_version` so a reader can see which mapping they are looking at.
 */
final class NistFunctions
{
    /**
     * @return array{
     *     version: string,
     *     sha256: string,
     *     functions: array<string, string>,
     *     rules: array<string, string>
     * }
     */
    public static function load(string $projectRoot): array
    {
        $path = rtrim($projectRoot, '/\\') . '/content/overlays/rule_nist_functions.yaml';

        if (!is_file($path)) {
            throw new RuntimeException('Missing content/overlays/rule_nist_functions.yaml');
        }

        $doc = Yaml::parse((string) file_get_contents($path));
        $functions = $doc['functions'] ?? null;
        $rules = $doc['rules'] ?? null;

        if (!is_array($functions) || $functions === []) {
            throw new RuntimeException('rule_nist_functions.yaml declares no functions');
        }

        if (!is_array($rules) || $rules === []) {
            throw new RuntimeException('rule_nist_functions.yaml declares no rule mapping');
        }

        $labels = [];
        $mapping = [];

        foreach ($functions as $code => $label) {
            $labels[(string) $code] = (string) $label;
        }

        foreach ($rules as $ruleId => $function) {
            $function = (string) $function;

            if (!isset($labels[$function])) {
                throw new RuntimeException(
                    "Rule {$ruleId} names NIST function '{$function}', which is not declared"
                );
            }

            $mapping[(string) $ruleId] = $function;
        }

        $version = (string) ($doc['version'] ?? '0.0.0');

        return [
            'version' => $version,
            'sha256' => CanonicalJson::sha256(['version' => $version, 'rules' => $mapping]),
            'functions' => $labels,
            'rules' => $mapping,
        ];
    }
}
