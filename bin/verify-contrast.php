<?php

declare(strict_types=1);

/**
 * Measures the accessibility claims instead of asserting them.
 *
 * docs/acceptance-matrix.md recorded two gaps: "WCAG 2.2 AA contrast and touch
 * targets — tokens are documented, never measured". This script measures them:
 * it reads the design tokens and component rules straight out of the stylesheets
 * and computes the WCAG 2.x contrast ratio for every pair the interface actually
 * renders, plus the declared target height of every interactive control.
 *
 * It is a static check, not a rendering test: it proves the token pairs and the
 * declared sizes, not that a browser paints them. That limit is stated in the
 * output so the claim cannot be read as stronger than it is.
 *
 *   php bin/verify-contrast.php
 */
$root = dirname(__DIR__);
$styles = [
    'styles.css' => (string) file_get_contents($root . '/web/src/styles.css'),
    'components.css' => (string) file_get_contents($root . '/web/src/components.css'),
    'layout.css' => (string) file_get_contents($root . '/web/src/layout.css'),
];

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("  PASS  %s%s\n", $label, $detail === '' ? '' : ' — ' . $detail);

        return;
    }

    $failed++;
    printf("  FAIL  %s%s\n", $label, $detail === '' ? '' : ' — ' . $detail);
}

$sheet = implode("\n", $styles);

// Comments would otherwise be captured as part of the selector in front of a
// rule, so a check for `.bs-button` would miss the block that declares it.
$sheet = (string) preg_replace('#/\*.*?\*/#s', '', $sheet);

// ── Tokens ──────────────────────────────────────────────────────────────────
preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\s*;/', $sheet, $matches, PREG_SET_ORDER);
$tokens = [];

foreach ($matches as $match) {
    $tokens[$match[1]] = strtolower($match[2]);
}

/**
 * WCAG 2.x relative luminance.
 */
function luminance(string $hex): float
{
    $channels = [];

    foreach ([1, 3, 5] as $offset) {
        $value = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $value <= 0.03928
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrast(string $a, string $b): float
{
    $la = luminance($a);
    $lb = luminance($b);
    $light = max($la, $lb);
    $dark = min($la, $lb);

    return ($light + 0.05) / ($dark + 0.05);
}

echo "== Contrast (WCAG 2.2 AA: 4.5:1 text, 3:1 large text and UI) ==\n";

/**
 * @var list<array{0: string, 1: string, 2: string, 3: float}> [label, fg token, bg token, minimum]
 */
$pairs = [
    ['body text on paper', 'color-ink', 'color-paper', 4.5],
    ['body text on canvas', 'color-ink', 'color-canvas', 4.5],
    ['headings on paper', 'color-heading', 'color-paper', 4.5],
    ['muted text on paper', 'color-muted', 'color-paper', 4.5],
    ['muted text on canvas', 'color-muted', 'color-canvas', 4.5],
    ['links on paper', 'color-accent-strong', 'color-paper', 4.5],
    ['accent on paper', 'color-accent', 'color-paper', 3.0],
    ['button label on accent', 'color-paper', 'color-accent-strong', 4.5],
    ['critical badge', 'sev-critical-fg', 'sev-critical-bg', 4.5],
    ['high badge', 'sev-high-fg', 'sev-high-bg', 4.5],
    ['medium badge', 'sev-medium-fg', 'sev-medium-bg', 4.5],
    ['low badge', 'sev-low-fg', 'sev-low-bg', 4.5],
    ['none badge', 'sev-none-fg', 'sev-none-bg', 4.5],
    ['critical notice', 'sev-critical-fg', 'sev-critical-bg', 4.5],
];

$missing = [];

foreach ($pairs as [$label, $fg, $bg, $minimum]) {
    if (!isset($tokens[$fg], $tokens[$bg])) {
        $missing[] = $label;
        continue;
    }

    $ratio = contrast($tokens[$fg], $tokens[$bg]);

    check(
        $label,
        $ratio >= $minimum,
        sprintf('%.2f:1 (minimum %.1f:1) %s on %s', $ratio, $minimum, $tokens[$fg], $tokens[$bg])
    );
}

check('every referenced colour token exists', $missing === [], implode(', ', $missing));

echo "\n== Target sizes (WCAG 2.2 AA 2.5.8: 24 px minimum) ==\n";

/**
 * @var array<string, array{0: int, 1: string}> selector => [minimum px, reason]
 */
$targets = [
    '.bs-button' => [44, 'primary and secondary actions'],
    '.bs-nav a' => [44, 'header navigation'],
    '.bs-select' => [44, 'form controls'],
    '.bs-input' => [44, 'form controls'],
    '.bs-term' => [24, 'inline glossary term'],
];

/**
 * The smallest declared min-height for a selector, across grouped rules.
 *
 * `.bs-select, .bs-input { … }` is one block for two selectors, so the check must
 * look inside the selector list rather than expect a block per class.
 */
function declaredMinHeight(string $sheet, string $selector): int
{
    $height = 0;

    preg_match_all('/([^{}]+)\{([^}]*)\}/', $sheet, $rules, PREG_SET_ORDER);

    foreach ($rules as $rule) {
        $selectors = array_map('trim', explode(',', $rule[1]));

        if (!in_array($selector, $selectors, true)) {
            continue;
        }

        if (preg_match('/min-height:\s*(\d+)px/', $rule[2], $declared) === 1) {
            $height = max($height, (int) $declared[1]);
        }
    }

    return $height;
}

foreach ($targets as $selector => [$minimum, $reason]) {
    $height = declaredMinHeight($sheet, $selector);

    check(
        sprintf('%s declares a %d px target (%s)', $selector, $minimum, $reason),
        $height >= $minimum,
        $height > 0 ? $height . ' px' : 'no min-height declared'
    );
}

echo "\n== Focus visibility ==\n";

check(
    ':focus-visible declares an outline',
    preg_match('/:focus-visible\s*\{[^}]*outline:/', $sheet) === 1
);
check(
    'the focus ring is offset from the control',
    preg_match('/--focus-offset:\s*\d+px/', $sheet) === 1
);

printf(
    "\nStatic measurement only: token pairs and declared sizes, not rendered pixels.\n"
    . "A browser pass (zoom, 320 px width, keyboard, screen reader) is still required.\n"
);
printf("\n%d passed, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
