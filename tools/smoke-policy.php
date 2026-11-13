<?php

declare(strict_types=1);

/**
 * Developer check: parse the two normative policy blocks and show key values.
 *
 * Usage: php tools/smoke-policy.php
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\Yaml;

$root = dirname(__DIR__);

$configMd = (string) file_get_contents($root . '/engine/CONFIG.md');
preg_match('/```yaml\n(.*?)\n```/s', $configMd, $configMatch);
$config = Yaml::parse($configMatch[1]);

$rulesMd = (string) file_get_contents($root . '/requirements/03_DECISION_RULES.md');
preg_match('/```yaml\n(.*?)\n```/s', $rulesMd, $rulesMatch);
$rules = Yaml::parse($rulesMatch[1]);

printf("ruleset_version      : %s\n", (string) $config['ruleset_version']);
printf("start / bounds       : %s / %s..%s\n",
    (string) $config['score_start_each_dimension'],
    (string) $config['score_lower_bound'],
    (string) $config['score_upper_bound']
);
printf("weights sum          : %d over %d dimensions\n",
    array_sum($config['weights_percent']),
    count($config['weights_percent'])
);
printf("appetite keys        : %s\n", implode(', ', array_keys($config['risk_appetite_delta'])));
printf("  conservative       : %s\n", json_encode($config['risk_appetite_delta']['conservative']));
printf("  balanced           : %s\n", json_encode($config['risk_appetite_delta']['balanced']));
printf("persona list         : %s\n", implode(', ', $config['board_persona_display_only']));
printf("ladder entries       : %d (first=%s)\n",
    count($config['score_ladder']),
    json_encode($config['score_ladder'][0])
);
printf("rules parsed         : %d\n", count($rules['rules']));
printf("first rule           : %s %s deltas=%s\n",
    (string) $rules['rules'][0]['id'],
    (string) $rules['rules'][0]['title'],
    json_encode($rules['rules'][0]['deltas'])
);
printf("last rule            : %s %s\n",
    (string) $rules['rules'][46]['id'],
    (string) $rules['rules'][46]['title']
);
