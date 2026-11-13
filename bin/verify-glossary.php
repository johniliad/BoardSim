<?php

declare(strict_types=1);

/**
 * Drift test for the in-product glossary.
 *
 * The glossary is derived, so it can only be wrong in one way: the sources it
 * derives from disagree, or something the engine emits has no entry. Both are
 * silent in the browser — the user just reads a definition that no longer
 * matches the score — so they are asserted here.
 *
 *   php bin/verify-glossary.php
 */
require __DIR__ . '/../src/bootstrap.php';

use BoardSim\Content\DifficultyViews;
use BoardSim\Content\Glossary;
use BoardSim\Content\OverlayLoader;
use BoardSim\Content\ScoringPolicyLoader;

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("  PASS  %s\n", $label);

        return;
    }

    $failed++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : ' — ' . $detail);
}

/**
 * all() rather than array_all(): the deployment target is PHP 8.2.
 *
 * @param array<int|string, mixed> $items
 */
function all(array $items, callable $predicate): bool
{
    foreach ($items as $item) {
        if ($predicate($item) !== true) {
            return false;
        }
    }

    return true;
}

$policy = (new ScoringPolicyLoader($root))->load();
$regulatory = (new OverlayLoader($root))->load();
$views = DifficultyViews::load($root);

$glossary = Glossary::build($root, $policy, $regulatory, $views);

printf("== Dimensions ==\n");

$dimensions = $glossary['dimensions'];
$codes = array_column($dimensions, 'code');
$weightCodes = array_map('strval', array_keys($policy['weights']));

check('ten dimensions are documented', count($dimensions) === 10, (string) count($dimensions));
check('the dimension set matches the policy weights', $codes === $weightCodes);
check('weights sum to 100', array_sum(array_column($dimensions, 'weight')) === 100);
check(
    'every dimension carries a name and a definition',
    all($dimensions, static fn (array $d): bool => $d['name'] !== '' && $d['definition'] !== '')
);
check(
    'every rule listed under a dimension exists in the ruleset',
    all(
        $dimensions,
        static fn (array $d): bool => all(
            $d['rules'],
            static fn (string $id): bool => $policy['ruleset']->has($id)
        )
    )
);

printf("\n== Rules ==\n");

$rules = $glossary['rules'];
$ruleIds = array_column($rules, 'id');
$byId = [];

foreach ($rules as $rule) {
    $byId[$rule['id']] = $rule;
}

check(
    'every rule in the ruleset is documented',
    count($rules) === $policy['ruleset']->count(),
    count($rules) . ' of ' . $policy['ruleset']->count()
);
check('rule ids are unique', count($ruleIds) === count(array_unique($ruleIds)));
check(
    'every rule carries a title and a rationale',
    all($rules, static fn (array $r): bool => $r['title'] !== '' && $r['rationale'] !== '')
);
check(
    'every rule moves at least one dimension',
    all($rules, static fn (array $r): bool => $r['dimensions'] !== [])
);

$mismatch = [];

foreach ($policy['ruleset']->all() as $rule) {
    $expected = [];

    foreach ($rule->deltas as $code => $delta) {
        if ((int) $delta !== 0) {
            $expected[] = (string) $code;
        }
    }

    sort($expected);

    if (($byId[$rule->id]['dimensions'] ?? null) !== $expected) {
        $mismatch[] = $rule->id;
    }
}

check('each rule lists exactly the dimensions its deltas move', $mismatch === [], implode(',', $mismatch));

$boardRules = array_values(array_filter($rules, static fn (array $r): bool => $r['board_rule'] === true));

check('the seven board rules are flagged', count($boardRules) === 7, (string) count($boardRules));

printf("\n== Instruments ==\n");

$instruments = $glossary['instruments'];
$lawInstruments = array_values(array_filter($instruments, static fn (array $i): bool => $i['nature'] === 'LAW'));

check(
    'every registered instrument is documented',
    count($instruments) === count($regulatory['instruments']),
    count($instruments) . ' of ' . count($regulatory['instruments'])
);
check(
    'every instrument carries a title',
    all($instruments, static fn (array $i): bool => $i['title'] !== '')
);
check(
    'every LAW instrument carries an official source URL',
    all($lawInstruments, static fn (array $i): bool => str_starts_with((string) $i['source_url'], 'http'))
);
check(
    'instrument ids are unique',
    count($instruments) === count(array_unique(array_column($instruments, 'id')))
);

printf("\n== Settings and statuses ==\n");

$groups = array_count_values(array_column($glossary['settings'], 'group'));

check('three difficulties are documented', ($groups['difficulty'] ?? 0) === 3, (string) ($groups['difficulty'] ?? 0));
check('three risk appetites are documented', ($groups['appetite'] ?? 0) === 3, (string) ($groups['appetite'] ?? 0));
check(
    'every persona is documented and marked display-only',
    ($groups['persona'] ?? 0) === count($policy['personas'])
        && all(
            array_values(array_filter($glossary['settings'], static fn (array $s): bool => $s['group'] === 'persona')),
            static fn (array $s): bool => ($s['display_only'] ?? false) === true
        )
);
check(
    'five score bands are documented',
    ($groups['band'] ?? 0) === count($policy['ladder']),
    (string) ($groups['band'] ?? 0)
);

$appetiteRules = [];

foreach (array_filter($glossary['settings'], static fn (array $s): bool => $s['group'] === 'appetite') as $appetite) {
    foreach (array_keys($appetite['modifiers'] ?? []) as $ruleId) {
        $appetiteRules[(string) $ruleId] = true;
    }
}

check(
    'every rule an appetite modifies is in the ruleset',
    all(array_keys($appetiteRules), static fn (string $id): bool => $policy['ruleset']->has($id)),
    implode(',', array_keys($appetiteRules))
);

check(
    'six regulatory statuses are documented',
    count($glossary['statuses']) === 6,
    (string) count($glossary['statuses'])
);
check(
    'status labels are humanised, not raw codes',
    all($glossary['statuses'], static fn (array $s): bool => !str_contains($s['label'], '_'))
);

printf("\n== NIST mapping ==\n");

$nistFunctions = $glossary['nist_functions'];

check(
    'the six CSF functions are declared',
    count($nistFunctions) === 6
        && array_keys($nistFunctions) === ['GV', 'ID', 'PR', 'DE', 'RS', 'RC'],
    implode(',', array_keys($nistFunctions))
);
check(
    'every rule names a declared NIST function',
    all($rules, static fn (array $r): bool => isset($nistFunctions[(string) $r['nist_function']]))
);
check(
    'the mapping covers every rule',
    count(array_filter(array_column($rules, 'nist_function'))) === count($rules),
    (string) count($rules)
);
check(
    'the glossary reports which mapping version is in force',
    ($glossary['nist_functions_version'] ?? '') !== ''
);

printf("\n== Payload ==\n");

check(
    'the hash is 64 hex characters',
    preg_match('/^[0-9a-f]{64}$/', (string) $glossary['glossary_sha256']) === 1
);
check(
    'the hash is stable across builds',
    $glossary['glossary_sha256'] === Glossary::build($root, $policy, $regulatory, $views)['glossary_sha256']
);
check('the version names all four sources', substr_count((string) $glossary['glossary_version'], '·') === 3);

printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
