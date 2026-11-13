<?php

declare(strict_types=1);

/**
 * Authoring aid: print the decision-relevant content of every pinned scenario.
 *
 * Used when writing Layer 2 regulatory facts, so that each asserted predicate can
 * be traced to a specific authored line rather than inferred.
 *
 * Usage: php tools/dump-scenario.php [BS-00X]
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\Yaml;

$root = dirname(__DIR__);
$filter = $argv[1] ?? null;
$files = glob($root . '/scenarios/BS-*.md') ?: [];
sort($files);

foreach ($files as $file) {
    $markdown = (string) file_get_contents($file);
    preg_match('/```yaml\n(.*?)\n```/s', $markdown, $match);
    $data = Yaml::parse($match[1]);

    $id = (string) ($data['scenario_id'] ?? '');

    if ($filter !== null && $id !== $filter) {
        continue;
    }

    echo str_repeat('=', 78) . "\n";
    printf("%s — %s\n", $id, (string) ($data['scenario_name'] ?? ''));
    printf("industry=%s track=%s role=%s\n",
        (string) ($data['industry'] ?? ''),
        (string) ($data['track'] ?? ''),
        (string) ($data['executive_role'] ?? '')
    );
    printf("refs: %s\n", implode(', ', (array) ($data['regulatory_refs'] ?? [])));
    printf("brief: %s\n", (string) ($data['starting_briefing'] ?? ''));

    foreach ((array) ($data['facts'] ?? []) as $fact) {
        printf("fact %s @%s: %s\n",
            (string) ($fact['id'] ?? ''),
            (string) ($fact['stage'] ?? ''),
            (string) ($fact['text'] ?? '')
        );
    }

    foreach ((array) ($data['stages'] ?? []) as $stage) {
        printf("\n[%s] %s\n", (string) ($stage['id'] ?? ''), (string) ($stage['question'] ?? ''));

        foreach ((array) ($stage['options'] ?? []) as $option) {
            $effects = [];

            foreach ((array) ($option['effects'] ?? []) as $key => $value) {
                $effects[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }

            printf("  %s  %s   {%s}\n",
                (string) ($option['id'] ?? ''),
                (string) ($option['label'] ?? ''),
                implode(', ', $effects)
            );
        }
    }

    echo "\n";
}
