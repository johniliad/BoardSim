<?php

declare(strict_types=1);

namespace BoardSim\Content;

/**
 * Strict, allowlist-only YAML subset parser.
 *
 * engine/RULE_SCHEMA.md: "Use a safe YAML subset (no aliases, anchors, custom
 * tags, embedded expressions, environment references or Python objects)." This
 * parser therefore refuses anything it does not explicitly understand rather
 * than degrading to a guess.
 *
 * Supported: nested mappings, block sequences, plain/single/double-quoted
 * scalars, multi-line folding of plain scalars (used by the pinned scenario
 * files), integers, floats, booleans, null and an inline empty list.
 *
 * Rejected (fail-closed): anchors (&), aliases (*), merge keys (<<), custom
 * tags (!), flow mappings ({...}), block scalars (| >), tab indentation.
 */
final class Yaml
{
    /**
     * @return array<array-key, mixed>
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        self::guard($text);

        $lines = self::splitLines($text);
        if ($lines === []) {
            return [];
        }

        $cursor = 0;
        $value = self::parseBlock($lines, $cursor, $lines[0]['indent']);

        if ($cursor !== count($lines)) {
            throw new YamlError('Unexpected content at line ' . $lines[$cursor]['no']);
        }

        if (!is_array($value)) {
            throw new YamlError('Document root must be a mapping or a sequence');
        }

        /** @var array<array-key, mixed> $value */
        return $value;
    }

    /**
     * Reject forbidden YAML features before any structural parsing happens.
     *
     * The scan runs over a comment-stripped view so that authored prose inside a
     * comment can never trip a lexical rule, while every token the parser will
     * actually read is still checked.
     */
    private static function guard(string $text): void
    {
        $code = self::codeView($text);

        $checks = [
            '/(?:^|[\s:,\-])&[A-Za-z_][A-Za-z0-9_-]*/m' => 'YAML anchor (&) is not permitted',
            '/(?:^|[\s:,\-])\*[A-Za-z_][A-Za-z0-9_-]*/m' => 'YAML alias (*) is not permitted',
            '/^\s*<<\s*:/m' => 'YAML merge key (<<) is not permitted',
            '/(?:^|[\s:,\-])![A-Za-z_!\/]/m' => 'YAML custom tag (!) is not permitted',
            '/^\s*[|>][-+]?\s*$/m' => 'YAML block scalars (| >) are not permitted',
        ];

        foreach ($checks as $pattern => $message) {
            if (preg_match($pattern, $code) === 1) {
                throw new YamlError($message);
            }
        }
    }

    /**
     * A copy of the document with full-line and inline comments blanked out.
     */
    private static function codeView(string $text): string
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $lines[] = '';
                continue;
            }

            $length = strlen($line);
            $quote = null;
            $cut = null;

            for ($index = 0; $index < $length; $index++) {
                $char = $line[$index];

                if ($quote !== null) {
                    if ($char === $quote) {
                        $quote = null;
                    }

                    continue;
                }

                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    continue;
                }

                if ($char === '#' && $index > 0 && ($line[$index - 1] === ' ' || $line[$index - 1] === "\t")) {
                    $cut = $index;
                    break;
                }
            }

            $lines[] = $cut === null ? $line : substr($line, 0, $cut);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{indent: int, text: string, no: int}>
     */
    private static function splitLines(string $text): array
    {
        $out = [];
        $no = 0;

        foreach (explode("\n", $text) as $raw) {
            $no++;
            $line = rtrim($raw);

            if ($line === '') {
                continue;
            }

            if (str_contains($line, "\t")) {
                throw new YamlError("Tab character is not permitted (line {$no})");
            }

            $trimmed = ltrim($line, ' ');
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $out[] = [
                'indent' => strlen($line) - strlen($trimmed),
                'text' => $trimmed,
                'no' => $no,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{indent: int, text: string, no: int}> $lines
     *
     * @return array<array-key, mixed>
     */
    private static function parseBlock(array $lines, int &$cursor, int $indent): array
    {
        if (!isset($lines[$cursor]) || $lines[$cursor]['indent'] < $indent) {
            return [];
        }

        if (self::isSeqLine($lines[$cursor]['text'])) {
            return self::parseSeq($lines, $cursor, $indent);
        }

        return self::parseMap($lines, $cursor, $indent);
    }

    /**
     * @param list<array{indent: int, text: string, no: int}> $lines
     *
     * @return list<mixed>
     */
    private static function parseSeq(array $lines, int &$cursor, int $indent): array
    {
        $out = [];
        $count = count($lines);
        $contentIndent = $indent + 2;

        while ($cursor < $count
            && $lines[$cursor]['indent'] === $indent
            && self::isSeqLine($lines[$cursor]['text'])
        ) {
            $text = $lines[$cursor]['text'];
            $content = $text === '-' ? '' : substr($text, 2);
            $cursor++;

            if (trim($content) === '') {
                $out[] = self::parseBlock($lines, $cursor, $contentIndent);
                continue;
            }

            if ($content[0] === '{' || $content[0] === '[') {
                $out[] = self::parseFlowItem($content);
                continue;
            }

            $pair = self::splitKeyValue($content);
            if ($pair === null) {
                $out[] = self::parseValue($content, $lines, $cursor, $contentIndent);
                continue;
            }

            [$key, $rawValue] = $pair;
            $map = [];
            $map[$key] = self::parseValue($rawValue, $lines, $cursor, $contentIndent);

            while ($cursor < $count
                && $lines[$cursor]['indent'] === $contentIndent
                && !self::isSeqLine($lines[$cursor]['text'])
            ) {
                $nextPair = self::splitKeyValue($lines[$cursor]['text']);
                if ($nextPair === null) {
                    throw new YamlError('Expected a mapping entry at line ' . $lines[$cursor]['no']);
                }

                [$nextKey, $nextValue] = $nextPair;
                if (array_key_exists($nextKey, $map)) {
                    throw new YamlError("Duplicate key '{$nextKey}' at line " . $lines[$cursor]['no']);
                }

                $cursor++;
                $map[$nextKey] = self::parseValue($nextValue, $lines, $cursor, $contentIndent);
            }

            $out[] = $map;
        }

        return $out;
    }

    /**
     * @param list<array{indent: int, text: string, no: int}> $lines
     *
     * @return array<string, mixed>
     */
    private static function parseMap(array $lines, int &$cursor, int $indent): array
    {
        $out = [];
        $count = count($lines);

        while ($cursor < $count
            && $lines[$cursor]['indent'] === $indent
            && !self::isSeqLine($lines[$cursor]['text'])
        ) {
            $pair = self::splitKeyValue($lines[$cursor]['text']);
            if ($pair === null) {
                throw new YamlError('Expected a mapping entry at line ' . $lines[$cursor]['no']);
            }

            [$key, $rawValue] = $pair;
            if (array_key_exists($key, $out)) {
                throw new YamlError("Duplicate key '{$key}' at line " . $lines[$cursor]['no']);
            }

            $cursor++;
            $out[$key] = self::parseValue($rawValue, $lines, $cursor, $indent);
        }

        return $out;
    }

    /**
     * @param list<array{indent: int, text: string, no: int}> $lines
     */
    private static function parseValue(string $raw, array $lines, int &$cursor, int $ownerIndent): mixed
    {
        $raw = trim($raw);

        if ($raw === '') {
            $count = count($lines);

            if ($cursor < $count && $lines[$cursor]['indent'] > $ownerIndent) {
                return self::parseBlock($lines, $cursor, $lines[$cursor]['indent']);
            }

            if ($cursor < $count
                && $lines[$cursor]['indent'] === $ownerIndent
                && self::isSeqLine($lines[$cursor]['text'])
            ) {
                return self::parseBlock($lines, $cursor, $ownerIndent);
            }

            return null;
        }

        if ($raw === '[]' || $raw === '{}') {
            return [];
        }

        // Single-line flow collections, required by the pinned engine/CONFIG.md
        // (flow mappings, flow sequences and a sequence of flow mappings).
        if ($raw[0] === '[' || $raw[0] === '{') {
            return self::parseFlowItem($raw);
        }

        if ($raw[0] === "'" || $raw[0] === '"') {
            $quote = $raw[0];
            $count = count($lines);

            // A quoted scalar may be folded across lines in the pinned files.
            while (!self::isQuoteClosed($raw, $quote) && $cursor < $count) {
                $raw .= ' ' . trim($lines[$cursor]['text']);
                $cursor++;
            }

            return self::quotedScalar($raw);
        }

        $value = $raw;
        $count = count($lines);

        while ($cursor < $count
            && $lines[$cursor]['indent'] > $ownerIndent
            && !self::isSeqLine($lines[$cursor]['text'])
            && self::splitKeyValue($lines[$cursor]['text']) === null
        ) {
            $value .= ' ' . $lines[$cursor]['text'];
            $cursor++;
        }

        return self::plainScalar($value);
    }

    private static function isSeqLine(string $text): bool
    {
        return $text === '-' || str_starts_with($text, '- ');
    }

    /**
     * Determine whether a quoted scalar has been closed, honouring the
     * single-quote escape ('' inside a single-quoted scalar).
     */
    private static function isQuoteClosed(string $raw, string $quote): bool
    {
        $length = strlen($raw);

        for ($index = 1; $index < $length; $index++) {
            if ($raw[$index] !== $quote) {
                continue;
            }

            if ($quote === "'" && $index + 1 < $length && $raw[$index + 1] === "'") {
                $index++;
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function splitKeyValue(string $text): ?array
    {
        $length = strlen($text);
        $quote = null;

        for ($index = 0; $index < $length; $index++) {
            $char = $text[$index];

            if ($quote !== null) {
                if ($char === $quote) {
                    if ($quote === "'" && $index + 1 < $length && $text[$index + 1] === "'") {
                        $index++;
                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char !== ':') {
                continue;
            }

            $next = $index + 1 < $length ? $text[$index + 1] : '';

            if ($next !== ' ' && $next !== '') {
                continue;
            }

            $key = rtrim(substr($text, 0, $index));
            if ($key === '') {
                return null;
            }

            return [$key, ltrim(substr($text, $index + 1))];
        }

        return null;
    }

    /**
     * A single-line flow collection: `[a, b]` or `{k: v, k2: v2}`.
     *
     * Only one level of nesting is supported and every element must fit on the
     * line, which is all the pinned configuration needs. Anything else throws.
     */
    private static function parseFlowItem(string $raw): mixed
    {
        $raw = trim($raw);

        if ($raw === '[]' || $raw === '{}') {
            return [];
        }

        $first = $raw[0];

        if ($first !== '[' && $first !== '{') {
            if ($first === "'" || $first === '"') {
                return self::quotedScalar($raw);
            }

            return self::plainScalar($raw);
        }

        $last = $raw[strlen($raw) - 1];

        if ($first === '[' && $last !== ']') {
            throw new YamlError('Unterminated flow sequence');
        }

        if ($first === '{' && $last !== '}') {
            throw new YamlError('Unterminated flow mapping');
        }

        $inner = substr($raw, 1, -1);

        if ($first === '[') {
            $items = [];

            foreach (self::splitFlow($inner) as $element) {
                $items[] = self::parseFlowItem($element);
            }

            return $items;
        }

        $map = [];

        foreach (self::splitFlow($inner) as $element) {
            $pair = self::splitKeyValue(trim($element));

            if ($pair === null) {
                throw new YamlError("Flow mapping entry without a key: '{$element}'");
            }

            $map[$pair[0]] = self::parseFlowItem($pair[1]);
        }

        return $map;
    }

    /**
     * Split a flow collection body on top-level commas, honouring quotes.
     *
     * @return list<string>
     */
    private static function splitFlow(string $inner): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $depth = 0;
        $length = strlen($inner);

        for ($index = 0; $index < $length; $index++) {
            $char = $inner[$index];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '[' || $char === '{') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ']' || $char === '}') {
                $depth--;
                $buffer .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return array_map('trim', array_filter($parts, static fn (string $p): bool => trim($p) !== ''));
    }

    private static function quotedScalar(string $raw): string
    {
        $quote = $raw[0];
        $body = substr($raw, 1);

        if ($quote === "'") {
            if (!str_ends_with($body, "'")) {
                throw new YamlError('Unterminated single-quoted scalar');
            }

            return str_replace("''", "'", substr($body, 0, -1));
        }

        if (!str_ends_with($body, '"')) {
            throw new YamlError('Unterminated double-quoted scalar');
        }

        return stripcslashes(substr($body, 0, -1));
    }

    private static function plainScalar(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '' || $trimmed === '~' || in_array($trimmed, ['null', 'Null', 'NULL'], true)) {
            return null;
        }

        if (in_array($trimmed, ['true', 'True', 'TRUE'], true)) {
            return true;
        }

        if (in_array($trimmed, ['false', 'False', 'FALSE'], true)) {
            return false;
        }

        if (preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        if (preg_match('/^-?\d+\.\d+$/', $trimmed) === 1) {
            return (float) $trimmed;
        }

        return $trimmed;
    }
}
