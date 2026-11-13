<?php

declare(strict_types=1);

namespace BoardSim\Content;

use BoardSim\Domain\Run\RunStore;

/**
 * Write the three pinned bundle rows into `content_bundles`.
 *
 * Extracted so `RunService::pinBundles()` and the reconciliation job
 * (`bin/migrate.php`) write byte-identical rows. Before this existed the two
 * would have been two copies of the same canonical payloads, and a divergence
 * would have been invisible until an audit compared them.
 *
 * `noteBundle()` is an INSERT IGNORE in the MySQL adapter, so seeding is
 * idempotent and safe to repeat on every migrate run.
 *
 * Why it matters at deploy time: `runs.policy_sha256` has a foreign key to
 * `content_bundles.bundle_sha256`. A fresh database therefore needs these rows
 * *before* the first run is created, which is exactly what the migrate job
 * guarantees on a host with no shell.
 */
final class BundleSeeder
{
    /** The scoring, regulatory and scenario-content artefacts a run pins. */
    public const EXPECTED = 3;

    /**
     * The three rows, as data.
     *
     * `bin/build-deploy.php` turns these into the `install.sql` seed statements
     * and `seed()` feeds the same array to the store — so the phpMyAdmin path and
     * the cron path write byte-identical canonical payloads. Duplicating the
     * arrays in the SQL generator is exactly the divergence this avoids.
     *
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $regulatory
     *
     * @return list<array{sha: string, kind: string, version: string, payload: string}>
     */
    public static function rows(
        array $policy,
        array $regulatory,
        string $contentVersion,
        string $contentSha256
    ): array {
        return [
            [
                'sha' => (string) $policy['policy_sha256'],
                'kind' => 'SCORING',
                'version' => (string) $policy['ruleset_version'],
                'payload' => CanonicalJson::encode([
                    'policy_sha256' => $policy['policy_sha256'],
                    'ruleset_version' => $policy['ruleset_version'],
                ]),
            ],
            [
                'sha' => (string) $regulatory['bundle_sha256'],
                'kind' => 'REGULATORY',
                'version' => (string) $regulatory['bundle']['regulatory_overlay_version'],
                'payload' => CanonicalJson::encode([
                    'bundle_sha256' => $regulatory['bundle_sha256'],
                    'version' => $regulatory['bundle']['regulatory_overlay_version'],
                ]),
            ],
            [
                'sha' => $contentSha256,
                'kind' => 'SCENARIO_CONTENT',
                'version' => $contentVersion,
                'payload' => CanonicalJson::encode([
                    'content_sha256' => $contentSha256,
                    'content_version' => $contentVersion,
                ]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $regulatory
     */
    public static function seed(
        RunStore $store,
        array $policy,
        array $regulatory,
        string $contentVersion,
        string $contentSha256
    ): void {
        foreach (self::rows($policy, $regulatory, $contentVersion, $contentSha256) as $row) {
            $store->noteBundle($row['sha'], $row['kind'], $row['version'], $row['payload']);
        }
    }
}
