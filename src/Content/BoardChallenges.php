<?php

declare(strict_types=1);

namespace BoardSim\Content;

use RuntimeException;

/**
 * Board-room challenge cards and the suggested preparation timer.
 *
 * This content is additive: it overrides nothing in the pinned policy, so unlike
 * the other overlays it needs no `from`/`from_rules` restatement. It is still
 * versioned and hashed, because the text a participant is shown must be part of
 * the record even though it can never change a score.
 *
 * Fail-closed rules, chosen so a broken overlay degrades to fewer prompts rather
 * than to invented ones:
 *   - exactly three cards;
 *   - one each from Chair, CFO and CEO, in any order;
 *   - non-empty unique ids and probes;
 *   - `preparation.scoring` must be false, because 06 forbids a coercive timer.
 */
final class BoardChallenges
{
    public const ROLES = ['Chair', 'CFO', 'CEO'];

    /**
     * @param list<array{id: string, from: string, title: string, probe: string}> $challenges
     * @param array<string, mixed>                                                 $preparation
     */
    private function __construct(
        private readonly array $challenges,
        private readonly array $preparation,
        private readonly string $version,
        private readonly string $sha256,
    ) {
    }

    public static function strictFallback(): self
    {
        return new self([], self::defaultPreparation(), '0.0.0', str_repeat('0', 64));
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultPreparation(): array
    {
        return [
            'suggested_minutes' => 5,
            'scoring' => false,
            'auto_start' => false,
            'hard_stop' => false,
            'note' => 'Suggested time only. Nothing happens when it elapses.',
        ];
    }

    public static function load(string $projectRoot): self
    {
        $path = rtrim($projectRoot, '/\\') . '/content/overlays/board_challenges.yaml';

        if (!is_file($path)) {
            return self::strictFallback();
        }

        $raw = file_get_contents($path);
        $doc = Yaml::parse($raw === false ? '' : $raw);

        $version = (string) ($doc['version'] ?? '');

        if ($version === '') {
            throw new RuntimeException('board_challenges.yaml has no version');
        }

        $list = $doc['challenges'] ?? null;

        if (!is_array($list) || count($list) !== 3) {
            throw new RuntimeException('board_challenges.yaml must define exactly three challenge cards');
        }

        $challenges = [];
        $ids = [];
        $roles = [];

        foreach ($list as $card) {
            if (!is_array($card)) {
                throw new RuntimeException('board_challenges.yaml has a malformed challenge entry');
            }

            $id = (string) ($card['id'] ?? '');
            $from = (string) ($card['from'] ?? '');
            $probe = trim((string) ($card['probe'] ?? ''));

            if ($id === '' || isset($ids[$id])) {
                throw new RuntimeException("board_challenges.yaml has an empty or duplicate id '{$id}'");
            }

            if (!in_array($from, self::ROLES, true)) {
                throw new RuntimeException("board challenge '{$id}' comes from '{$from}', not chair/CFO/CEO");
            }

            if ($probe === '') {
                throw new RuntimeException("board challenge '{$id}' has no probe");
            }

            $ids[$id] = true;
            $roles[$from] = true;
            $challenges[] = [
                'id' => $id,
                'from' => $from,
                'title' => (string) ($card['title'] ?? $from),
                'probe' => $probe,
            ];
        }

        foreach (self::ROLES as $role) {
            if (!isset($roles[$role])) {
                throw new RuntimeException("board_challenges.yaml has no card from {$role}");
            }
        }

        $preparation = $doc['preparation'] ?? null;

        if (!is_array($preparation)) {
            throw new RuntimeException('board_challenges.yaml has no preparation block');
        }

        if (($preparation['scoring'] ?? null) !== false) {
            throw new RuntimeException('preparation.scoring must be false: the timer is never a scoring input');
        }

        // Fixed order so the board room never reshuffles between loads.
        usort(
            $challenges,
            static fn (array $a, array $b): int => array_search($a['from'], self::ROLES, true)
                <=> array_search($b['from'], self::ROLES, true)
        );

        return new self(
            $challenges,
            [
                'suggested_minutes' => (int) ($preparation['suggested_minutes'] ?? 5),
                'scoring' => false,
                'auto_start' => (bool) ($preparation['auto_start'] ?? false),
                'hard_stop' => (bool) ($preparation['hard_stop'] ?? false),
                'note' => (string) ($preparation['note'] ?? ''),
            ],
            $version,
            hash('sha256', $raw === false ? '' : $raw),
        );
    }

    /**
     * @return list<array{id: string, from: string, title: string, probe: string}>
     */
    public function all(): array
    {
        return $this->challenges;
    }

    /**
     * @return array<string, mixed>
     */
    public function preparation(): array
    {
        return $this->preparation;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }
}
