<?php

declare(strict_types=1);

/** Authoring aid: organisation profile per scenario. Usage: php tools/dump-org.php */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\Yaml;

$root = dirname(__DIR__);
$files = glob($root . '/scenarios/BS-*.md') ?: [];
sort($files);

foreach ($files as $file) {
    $markdown = (string) file_get_contents($file);
    preg_match('/```yaml\n(.*?)\n```/s', $markdown, $match);
    $data = Yaml::parse($match[1]);
    $org = is_array($data['organization'] ?? null) ? $data['organization'] : [];

    printf(
        "%s  %-24s emp=%-5s rev=%-5s  %s\n",
        (string) $data['scenario_id'],
        (string) $data['industry'],
        (string) ($org['employees'] ?? '?'),
        (string) ($org['annual_revenue_eur_m'] ?? '?'),
        (string) ($org['country'] ?? '')
    );
}
