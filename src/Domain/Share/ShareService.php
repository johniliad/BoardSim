<?php

declare(strict_types=1);

namespace BoardSim\Domain\Share;

use BoardSim\Content\RetentionPolicy;
use BoardSim\Domain\Run\DomainError;
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
 *   - Expiry is the same 7 days as retention. A link cannot outlive its data, and
 *     when the runs are purged the link stops resolving rather than erroring
 *     ambiguously.
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
        // Aggregating first means a share can never be created for a set that is
        // not a valid profile: wrong window, mixed versions, unfinished runs.
        $profile = $this->runs->profile($runIds);

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

        $now = $nowUtc === null ? time() : (int) strtotime($nowUtc);

        if ($now === false) {
            throw DomainError::invalid('Unparseable timestamp');
        }

        $days = $this->retention->shareTtlDays();
        $token = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', $now + ($days * 86400));

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
            'expires_in_days' => $days,
            'runs' => count($runIds),
            'profile' => $profile['segments'],
            'note' => 'This token is shown once and is not recoverable from the server: only its '
                . 'hash is stored. Anyone holding the link can read the profile until it expires or '
                . 'is revoked. Revoking does not delete the runs.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $token, ?string $nowUtc = null): array
    {
        $record = $this->load($token);
        $now = $nowUtc === null ? time() : (int) strtotime($nowUtc);

        if ($record['revoked_at'] !== null) {
            throw DomainError::notFound('Share link', 'revoked');
        }

        if (strtotime((string) $record['expires_at']) < $now) {
            throw DomainError::notFound('Share link', 'expired');
        }

        $remaining = (int) ceil((strtotime((string) $record['expires_at']) - $now) / 86400);

        try {
            $profile = $this->runs->profile((array) $record['run_ids']);
        } catch (DomainError $e) {
            // The runs were purged or deleted while the link was live.
            throw DomainError::notFound(
                'Share link',
                'its underlying runs were deleted, so this link no longer resolves'
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

        $at = gmdate('Y-m-d\TH:i:s\Z', $nowUtc === null ? time() : (int) strtotime($nowUtc));
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
        if ($token === '' || preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            throw DomainError::notFound('Share link', 'malformed token');
        }

        $record = $this->store->find(hash('sha256', $token));

        if ($record === null) {
            throw DomainError::notFound('Share link', 'unknown token');
        }

        return $record;
    }
}
