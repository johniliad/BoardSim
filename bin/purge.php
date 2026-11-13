<?php

declare(strict_types=1);

/**
 * Retention job. Physically deletes every run past the ceiling in
 * content/overlays/retention.yaml (7 days from last activity).
 *
 * Intended for cron, not for the public API:
 *
 *   BOARDSIM_DSN=... php bin/purge.php            # apply
 *   php bin/purge.php --dry-run                   # show the cutoff only
 *
 * It prints what it removed, so the cron log doubles as the audit trail an
 * adviser will ask for.
 */

require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\OverlayLoader;
use BoardSim\Content\PinnedScenarios;
use BoardSim\Content\ScoringPolicyLoader;
use BoardSim\Domain\Run\FileRunStore;
use BoardSim\Domain\Run\RunService;
use BoardSim\Infrastructure\MysqlRunStore;

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv, true);
$local = \BoardSim\Infrastructure\EnvFile::bootstrap($root);

$policy = (new ScoringPolicyLoader($root))->load();
$pinned = new PinnedScenarios($root);

$dsn = getenv('BOARDSIM_DSN') ?: ($local['dsn'] ?? '');

if ($dsn === '' || $dsn === false) {
    // Mirrors public/api/index.php: dev uses the file store, production MySQL.
    $store = new FileRunStore($root . '/var/dev-runs.json');
    printf("Store: file (%s)\n", 'var/dev-runs.json');
} else {
    $pdo = new PDO(
        (string) $dsn,
        (string) (getenv('BOARDSIM_USER') ?: ($local['user'] ?? '')),
        (string) (getenv('BOARDSIM_PASS') ?: ($local['pass'] ?? '')),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $store = new MysqlRunStore($pdo);
    echo "Store: mysql\n";
}

$service = new RunService(
    $store,
    (new OverlayLoader($root))->load(),
    $policy,
    $pinned->loadAll(),
    $pinned->contentVersion(),
    $pinned->contentSha256(),
    ['hash_chain'],
    null,
    null,
    $root
);

$retention = $service->retention();

printf(
    "%d-day ceiling (share links: %d days), clock=%s, delete=%s\n",
    $retention['run_retention_days'],
    $retention['share_ttl_days'],
    $retention['clock'],
    $retention['delete_model']
);

if ($dryRun) {
    // Same source the purge uses, so the printed cutoff cannot disagree with what
    // a real run would delete (a naive calendar subtract drifts around DST).
    printf(
        "Dry run. Cutoff would be %s; nothing was deleted.\n",
        \BoardSim\Content\RetentionPolicy::load($root)->cutoff()
    );

    exit(0);
}

$result = $service->purgeExpired();

printf("Cutoff %s: purged %d run(s)\n", $result['cutoff_utc'], $result['purged']);

foreach ($result['run_ids'] as $runId) {
    printf("  deleted %s\n", $runId);
}

echo $result['note'], "\n";
