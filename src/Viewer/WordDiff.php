<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\AuditorBundle\Tests\Viewer\WordDiffTest;

/**
 * Word by word comparison of two texts, for the details of a long text change.
 *
 * The common start and end are set aside, then the rest is compared by longest common subsequence. Texts longer than
 * MAX_WORDS words, or whose differing parts are too long to compare, give null: show the old and new texts instead.
 *
 * @see WordDiffTest
 */
final class WordDiff
{
    public const int MAX_WORDS = 2_000;

    /**
     * Largest number of cells of the comparison table of the differing parts.
     */
    private const int MAX_CELLS = 250_000;

    /**
     * @return null|list<array{0: 'delete'|'equal'|'insert', 1: string}> runs of text, in reading order
     */
    public static function diff(string $old, string $new): ?array
    {
        $a = self::tokens($old);
        $b = self::tokens($new);
        if (null === $a || null === $b || self::words($a) > self::MAX_WORDS || self::words($b) > self::MAX_WORDS) {
            return null;
        }

        $start = 0;
        while ($start < \count($a) && $start < \count($b) && $a[$start] === $b[$start]) {
            ++$start;
        }

        $end = 0;
        while ($end < \count($a) - $start && $end < \count($b) - $start && $a[\count($a) - 1 - $end] === $b[\count($b) - 1 - $end]) {
            ++$end;
        }

        $middleA = \array_slice($a, $start, \count($a) - $start - $end);
        $middleB = \array_slice($b, $start, \count($b) - $start - $end);
        if ((\count($middleA) + 1) * (\count($middleB) + 1) > self::MAX_CELLS) {
            return null;
        }

        $runs = [];
        foreach (\array_slice($a, 0, $start) as $token) {
            $runs[] = ['equal', $token];
        }

        array_push($runs, ...self::lcs($middleA, $middleB));
        foreach (\array_slice($a, \count($a) - $end) as $token) {
            $runs[] = ['equal', $token];
        }

        return self::merge($runs);
    }

    /**
     * Words and the spaces between them, as separate tokens.
     *
     * @return null|list<string> null for a text that is not valid UTF-8
     */
    private static function tokens(string $text): ?array
    {
        if ('' === $text) {
            return [];
        }

        $tokens = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return false === $tokens ? null : $tokens;
    }

    /**
     * @param list<string> $tokens
     */
    private static function words(array $tokens): int
    {
        return \count(array_filter($tokens, static fn (string $token): bool => '' !== mb_trim($token)));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<array{0: 'delete'|'equal'|'insert', 1: string}>
     */
    private static function lcs(array $a, array $b): array
    {
        $n = \count($a);
        $m = \count($b);

        // $lengths[$i][$j]: length of the longest common subsequence of $a from $i and $b from $j
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $a[$i] === $b[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $runs = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $runs[] = ['equal', $a[$i++]];
                ++$j;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $runs[] = ['delete', $a[$i++]];
            } else {
                $runs[] = ['insert', $b[$j++]];
            }
        }

        while ($i < $n) {
            $runs[] = ['delete', $a[$i++]];
        }

        while ($j < $m) {
            $runs[] = ['insert', $b[$j++]];
        }

        return $runs;
    }

    /**
     * Joins consecutive runs of the same kind; a space between two deleted (or inserted) words joins them, all the
     * deletions of a change coming before its insertions.
     *
     * @param list<array{0: 'delete'|'equal'|'insert', 1: string}> $runs
     *
     * @return list<array{0: 'delete'|'equal'|'insert', 1: string}>
     */
    private static function merge(array $runs): array
    {
        // a space that is the only common token between two changes belongs to the change
        $count = \count($runs);
        for ($index = 1; $index < $count - 1; ++$index) {
            if ('equal' === $runs[$index][0] && '' === mb_trim($runs[$index][1]) && 'equal' !== $runs[$index - 1][0] && 'equal' !== $runs[$index + 1][0]) {
                $runs[$index] = ['delete', $runs[$index][1]];
                array_splice($runs, $index + 1, 0, [['insert', $runs[$index][1]]]);
                ++$count;
                ++$index;
            }
        }

        // within a change, deletions first, then insertions
        $sorted = [];
        $deleted = [];
        $inserted = [];
        foreach ([...$runs, ['equal', '']] as [$kind, $text]) {
            if ('delete' === $kind) {
                $deleted[] = $text;

                continue;
            }

            if ('insert' === $kind) {
                $inserted[] = $text;

                continue;
            }

            if ([] !== $deleted) {
                $sorted[] = ['delete', implode('', $deleted)];
            }

            if ([] !== $inserted) {
                $sorted[] = ['insert', implode('', $inserted)];
            }

            $deleted = [];
            $inserted = [];
            if ('' !== $text) {
                $sorted[] = ['equal', $text];
            }
        }

        $merged = [];
        foreach ($sorted as $run) {
            $last = array_key_last($merged);
            if (null !== $last && $merged[$last][0] === $run[0]) {
                $merged[$last][1] .= $run[1];
            } else {
                $merged[] = $run;
            }
        }

        return $merged;
    }
}
