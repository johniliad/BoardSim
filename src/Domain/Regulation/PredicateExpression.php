<?php

declare(strict_types=1);

namespace BoardSim\Domain\Regulation;

use InvalidArgumentException;

/**
 * Three-valued boolean expression over authored regulatory predicates.
 *
 * Grammar (deliberately tiny, per engine/RULE_SCHEMA.md "no arbitrary formulas"):
 *   expression := or
 *   or         := and ("OR" and)*
 *   and        := not ("AND" not)*
 *   not        := "NOT" not | primary
 *   primary    := "(" expression ")" | variable
 *
 * There is no comparison operator, no arithmetic and no function call, so no
 * authored string can become executable code.
 */
final class PredicateExpression
{
    /** @var list<array{0: string, 1: string}> */
    private readonly array $tokens;

    /** @var array<int, mixed> */
    private readonly array $ast;

    public function __construct(private readonly string $source)
    {
        $this->tokens = self::tokenize($source);

        $cursor = 0;
        $this->ast = $this->buildOr($cursor);

        if ($cursor !== count($this->tokens)) {
            throw new InvalidArgumentException(
                "Unexpected trailing token in predicate expression: {$source}"
            );
        }
    }

    /**
     * Variable names referenced by an expression, for load-time vocabulary checks.
     *
     * @return list<string>
     */
    public static function identifiers(string $source): array
    {
        $names = [];

        foreach (self::tokenize($source) as [$type, $value]) {
            if ($type === 'var') {
                $names[$value] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param callable(string): TriBool $lookup
     */
    public function evaluate(callable $lookup): TriBool
    {
        return $this->evaluateNode($this->ast, $lookup);
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @param callable(string): TriBool $lookup
     */
    private function evaluateNode(mixed $node, callable $lookup): TriBool
    {
        /** @var array<int, mixed> $node */
        return match ($node[0]) {
            'var' => $lookup((string) $node[1]),
            'not' => $this->evaluateNode($node[1], $lookup)->negate(),
            'and' => TriBool::all(
                $this->evaluateNode($node[1], $lookup),
                $this->evaluateNode($node[2], $lookup)
            ),
            'or' => TriBool::any(
                $this->evaluateNode($node[1], $lookup),
                $this->evaluateNode($node[2], $lookup)
            ),
            default => throw new InvalidArgumentException('Unknown predicate node'),
        };
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $index = 0;

        while ($index < $length) {
            $char = $source[$index];

            if ($char === ' ') {
                $index++;
                continue;
            }

            if ($char === '(' || $char === ')') {
                $tokens[] = ['paren', $char];
                $index++;
                continue;
            }

            if (preg_match('/[A-Za-z_]/', $char) === 1) {
                $start = $index;
                while ($index < $length && preg_match('/[A-Za-z0-9_]/', $source[$index]) === 1) {
                    $index++;
                }

                $word = substr($source, $start, $index - $start);
                $keyword = strtoupper($word);

                $tokens[] = in_array($keyword, ['AND', 'OR', 'NOT'], true)
                    ? ['op', $keyword]
                    : ['var', $word];

                continue;
            }

            throw new InvalidArgumentException(
                "Unexpected character '{$char}' in predicate expression: {$source}"
            );
        }

        if ($tokens === []) {
            throw new InvalidArgumentException('Empty predicate expression');
        }

        return $tokens;
    }

    /**
     * @param array<int, mixed> $node
     */
    private function buildOr(int &$cursor): array
    {
        $node = $this->buildAnd($cursor);

        while ($this->peekKeyword($cursor) === 'OR') {
            $cursor++;
            $node = ['or', $node, $this->buildAnd($cursor)];
        }

        return $node;
    }

    /**
     * @param array<int, mixed> $node
     */
    private function buildAnd(int &$cursor): array
    {
        $node = $this->buildNot($cursor);

        while ($this->peekKeyword($cursor) === 'AND') {
            $cursor++;
            $node = ['and', $node, $this->buildNot($cursor)];
        }

        return $node;
    }

    /**
     * @param array<int, mixed> $node
     */
    private function buildNot(int &$cursor): array
    {
        if ($this->peekKeyword($cursor) === 'NOT') {
            $cursor++;

            return ['not', $this->buildNot($cursor)];
        }

        return $this->buildPrimary($cursor);
    }

    /**
     * @param array<int, mixed> $node
     */
    private function buildPrimary(int &$cursor): array
    {
        $token = $this->tokens[$cursor] ?? null;

        if ($token === null) {
            throw new InvalidArgumentException("Unexpected end of predicate expression: {$this->source}");
        }

        if ($token[0] === 'paren' && $token[1] === '(') {
            $cursor++;
            $node = $this->buildOr($cursor);

            if (($this->tokens[$cursor] ?? null) !== ['paren', ')']) {
                throw new InvalidArgumentException("Unbalanced parenthesis in: {$this->source}");
            }

            $cursor++;

            return $node;
        }

        if ($token[0] === 'var') {
            $cursor++;

            return ['var', $token[1]];
        }

        throw new InvalidArgumentException("Unexpected token in predicate expression: {$this->source}");
    }

    private function peekKeyword(int $cursor): ?string
    {
        $token = $this->tokens[$cursor] ?? null;

        if ($token === null || $token[0] !== 'op') {
            return null;
        }

        return $token[1];
    }
}
