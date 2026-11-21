<?php

declare(strict_types=1);

namespace BoardSim\Domain\Share;

use BoardSim\Content\RetentionPolicy;
use BoardSim\Domain\Run\DomainError;
use BoardSim\Domain\Run\InputValidator;
use BoardSim\Domain\Run\RunService;

/**
 * Pseudonymous share links.
 *
 * Three properties worth stating, because each is a decision rather than a detail:
 *
 *   - The token is 32 random bytes, returned exactly once, and only its SHA-256 is
 *     stored. A database read cannot reconstruct a working link.
 *   - A token points at a *snapshot of run ids*, not at a profile object. There is
 *     no profile table to keep in sync, and the shared view is recomputed from the
 *     runs on every request, so it can never drift from what those runs say.
 *   - Expiry is capped at the earliest included run's deletion deadline. Sharing
 *     never extends retention, and a live link cannot outlive its data.
 */
final class ShareService
{
    public function __construct(
        private readonly RunService $runs,
        private readonly ShareStore $store,
        private readonly RetentionPolicy $retention,
    ) {
    }

    /**
     * @param list<string> $runIds
     *
     * @return array<string, mixed>
     */
    public function create(array $runIds, ?string $nowUtc = null): array
    {
        $runIds = InputValidator::runIdList($runIds);

        // Aggregating first means a share can never be created for a set that is
        // not a valid profile: wrong window, mixed versions, unfinished runs.
        $profile = $this->runs->profile($runIds);

        if (($profile['selection']['missing_run_ids'] ?? []) !== []
            || ($profile['selection']['unfinished_run_ids'] ?? []) !== []
            || ($profile['selection']['incompatible_run_ids'] ?? []) !== []
        ) {
            throw DomainError::invalid(
                'Every shared run must exist, be finalized, and match the release loaded by this deployment.'
            );
        }

        $eligible = false;

        foreach ($profile['segments'] as $segment) {
            if (($segment['status'] ?? '') === 'eligible') {
                $eligible = true;
            }
        }

        if (!$eligible) {
            throw DomainError::invalid(
                'A share link needs at least one eligible profile segment (3 to 5 completed '
                . 'scenarios in the same ruleset, content and difficulty).'
            );
        }

        $now = $this->timestamp($nowUtc);

        $days = $this->retention->shareTtlDays();
        $token = bin2hex(random_bytes(32));
        $ttlExpiry = $now + ($days * 86400);
        $oldestFinalized = (string) ($profile['retention']['oldest_run_finalized_at'] ?? '');
        $oldestTimestamp = strtotime($oldestFinalized);

        if ($oldestFinalized === '' || $oldestTimestamp === false) {
            throw DomainError::invalid('A share link requires finalized runs with a retention deadline.');
        }

        $runExpiry = $oldestTimestamp + ($this->retention->runRetentionDays() * 86400);
        $expiry = min($ttlExpiry, $runExpiry);

        if ($expiry <= $now) {
            throw DomainError::invalid('The selected runs have reached their retention deadline and cannot be shared.');
        }

        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', $expiry);
        $expiresInDays = (int) ceil(($expiry - $now) / 86400);

        $this->store->put(hash('sha256', $token), [
            'run_ids' => array_values(array_map('strval', $runIds)),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ]);

        return [
            'token' => $token,
            'path' => '/#/shared/' . $token,
            'expires_at' => $expiresAt,
            'expires_in_days' => $expiresInDays,
            'runs' => count($runIds),
            'profile' => $profile['segments'],
            'note' => 'This token is shown once and is not recoverable from the server: only its '
                . 'hash is stored. Anyone holding the link can read the profile until it expires or '
                . 'is revoked. The link is capped at the earliest run deletion deadline; sharing '
                . 'does not extend retention. Revoking does not delete the runs.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $token, ?string $nowUtc = null): array
    {
        $record = $this->load($token);
        $now = $this->timestamp($nowUtc);

        if ($record['revoked_at'] !== null) {
            throw DomainError::notFound('Share link', 'revoked');
        }

        if ((int) strtotime((string) $record['expires_at']) <= $now) {
            throw DomainError::notFound('Share link', 'expired');
        }

        $remaining = (int) ceil((strtotime((string) $record['expires_at']) - $now) / 86400);

        $profile = $this->runs->profile((array) $record['run_ids']);

        if (($profile['selection']['missing_run_ids'] ?? []) !== []
            || ($profile['selection']['unfinished_run_ids'] ?? []) !== []
            || ($profile['selection']['incompatible_run_ids'] ?? []) !== []
        ) {
            throw DomainError::notFound(
                'Share link',
                'its underlying runs were deleted or can no longer be interpreted, so this link no longer resolves'
            );
        }

        return [
            'expires_at' => (string) $record['expires_at'],
            'expires_in_days' => max(0, $remaining),
            'read_only' => true,
            'profile' => $profile,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function revoke(string $token, ?string $nowUtc = null): array
    {
        $hash = hash('sha256', $token);
        $record = $this->load($token);

        if ($record['revoked_at'] !== null) {
            return [
                'revoked' => true,
                'already_revoked' => true,
                'revoked_at' => (string) $record['revoked_at'],
                'note' => 'This link was already revoked; the runs themselves are untouched.',
            ];
        }

        $at = gmdate('Y-m-d\TH:i:s\Z', $this->timestamp($nowUtc));
        $this->store->revoke($hash, $at);

        return [
            'revoked' => true,
            'already_revoked' => false,
            'revoked_at' => $at,
            'note' => 'The link no longer resolves. The runs were not deleted, and the link cannot '
                . 'be re-enabled: create a new one.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $token): array
    {
        try {
            $token = InputValidator::shareToken($token);
        } catch (DomainError) {
            throw DomainError::notFound('Share link', 'malformed token');
        }

        $record = $this->store->find(hash('sha256', $token));

        if ($record === null) {
            throw DomainError::notFound('Share link', 'unknown token');
        }

        return $record;
    }

    private function timestamp(?string $utc): int
    {
        if ($utc === null) {
            return time();
        }

        $parsed = strtotime($utc);

        if ($parsed === false) {
            throw DomainError::invalid('Unparseable timestamp');
        }

        return $parsed;
    }
}
