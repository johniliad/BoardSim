<?php

declare(strict_types=1);

namespace BoardSim\Infrastructure;

/**
 * Read the release stamp that `bin/build-deploy.php` writes next to the code.
 *
 * A deployment made by copying a zip has no `git` and no shell, so the only way
 * to answer "what is actually running here?" is a file that travels with the
 * artefact. `/v1/health` reports it, and `bin/verify-deploy.php
 * --expect-release=<sha>` asserts it, which turns "I uploaded something" into
 * "the origin is running the bytes I built".
 *
 * A checkout without the file reports `unreleased`, so a development origin can
 * never be mistaken for a stamped release.
 */
final class Release
{
    /**
     * @return array{version: string, sha256: string|null, built_at: string|null, commit: string|null}
     */
    public static function load(string $projectRoot): array
    {
        $unknown = [
            'version' => 'unreleased',
            'sha256' => null,
            'built_at' => null,
            'commit' => null,
        ];

        $path = rtrim($projectRoot, '/\\') . '/release.json';

        if (!is_file($path)) {
            return $unknown;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return $unknown;
        }

        return [
            'version' => (string) ($decoded['version'] ?? 'unknown'),
            'sha256' => isset($decoded['tree_sha256']) ? (string) $decoded['tree_sha256'] : null,
            'built_at' => isset($decoded['built_at']) ? (string) $decoded['built_at'] : null,
            'commit' => isset($decoded['commit']) ? (string) $decoded['commit'] : null,
        ];
    }
}
