<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * How long anything is kept, in one place.
 *
 * The values are a decision, not an implementation detail, so they live in a
 * versioned overlay rather than in a constant somewhere. The loader enforces the
 * decision's shape. ShareService caps each link at the earliest included run's
 * deletion deadline, and a profile window may never exceed the retention ceiling,
 * because a profile that outlives its runs would silently lose participants.
 */
final class RetentionPolicy
{
    private function __construct(
        private readonly int $shareTtlDays,
        private readonly int $runRetentionDays,
        private readonly int $profileWindowDays,
        private readonly string $deleteModel,
        private readonly int $backupRotationDays,
        private readonly string $version,
        private readonly string $sha256,
    ) {
    }

    public static function load(string $projectRoot): self
    {
        $path = rtrim($projectRoot, '/\\') . '/content/overlays/retention.yaml';

        if (!is_file($path)) {
            throw new RuntimeException('content/overlays/retention.yaml is required');
        }

        $raw = file_get_contents($path);
        $raw = $raw === false ? '' : $raw;
        $doc = Yaml::parse($raw);

        $share = (int) ($doc['share_ttl_days'] ?? 0);
        $runs = (int) ($doc['run_retention_days'] ?? 0);
        $window = (int) ($doc['profile_window_days'] ?? 0);
        $deleteModel = (string) ($doc['delete_model'] ?? '');
        $backupRotation = (int) ($doc['backup_rotation_days'] ?? 0);

        if ($backupRotation < 0) {
            throw new RuntimeException('retention.yaml: backup_rotation_days cannot be negative');
        }

        if ($share <= 0 || $runs <= 0) {
            throw new RuntimeException('retention.yaml must set positive share_ttl_days and run_retention_days');
        }

        if ($window > $runs) {
            throw new RuntimeException(
                "retention.yaml: profile_window_days ({$window}) exceeds run_retention_days ({$runs})"
            );
        }

        if ($deleteModel !== 'physical') {
            throw new RuntimeException('retention.yaml: delete_model must be physical (decision A)');
        }

        if (($doc['soft_delete'] ?? null) !== false) {
            throw new RuntimeException('retention.yaml: soft_delete must be false (decision A)');
        }

        return new self(
            $share,
            $runs,
            $window,
            $deleteModel,
            $backupRotation,
            (string) ($doc['version'] ?? ''),
            hash('sha256', $raw)
        );
    }

    /**
     * Inclusive cutoff: anything finalised strictly before this instant is expired.
     */
    public function cutoff(?string $nowUtc = null): string
    {
        $now = $nowUtc === null ? time() : strtotime($nowUtc);

        if ($now === false) {
            throw new RuntimeException('retention policy received an unparseable timestamp');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $now - ($this->runRetentionDays * 86400));
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'share_ttl_days' => $this->shareTtlDays,
            'run_retention_days' => $this->runRetentionDays,
            'profile_window_days' => $this->profileWindowDays,
            'delete_model' => $this->deleteModel,
            'clock' => 'finalized_at_or_created_at',
            // The number a participant should actually be told. Deletion is complete
            // in the live system at the ceiling, but backups include these tables, so a
            // copy can persist until the backup rotates.
            'backup_rotation_days' => $this->backupRotationDays,
            'worst_case_days' => $this->runRetentionDays + $this->backupRotationDays,
            'version' => $this->version,
            'sha256' => $this->sha256,
        ];
    }

    public function shareTtlDays(): int
    {
        return $this->shareTtlDays;
    }

    public function runRetentionDays(): int
    {
        return $this->runRetentionDays;
    }

    public function profileWindowDays(): int
    {
        return $this->profileWindowDays;
    }
}
