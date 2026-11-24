<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\AuditorBundle\Viewer\WordDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class WordDiffTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: string}> $expected
     */
    #[DataProvider('provideDiffCases')]
    public function testDiff(string $old, string $new, array $expected): void
    {
        $this->assertSame($expected, WordDiff::diff($old, $new));
    }

    /**
     * @return iterable<string, array{string, string, list<array{0: string, 1: string}>}>
     */
    public static function provideDiffCases(): iterable
    {
        yield 'words added before a punctuation mark' => ['Why audit?', 'Why audit your entities?', [['equal', 'Why audit'], ['insert', ' your entities'], ['equal', '?']]];

        yield 'punctuation apart from the word it follows' => [
            'Aut adipisci mollitia.',
            'Aut adipisci mollitia - Revised Edition',
            [['equal', 'Aut adipisci mollitia'], ['delete', '.'], ['insert', ' - Revised Edition']],
        ];

        yield 'punctuation changed' => ['Hello, world', 'Hello; world', [['equal', 'Hello'], ['delete', ','], ['insert', ';'], ['equal', ' world']]];

        yield 'apostrophe' => ["Filomena Welch's comment", "Filomena Welsh's comment", [['equal', 'Filomena '], ['delete', 'Welch'], ['insert', 'Welsh'], ['equal', "'s comment"]]];

        yield 'word replaced' => ['the cat sat', 'the dog sat', [['equal', 'the '], ['delete', 'cat'], ['insert', 'dog'], ['equal', ' sat']]];

        yield 'same' => ['same text', 'same text', [['equal', 'same text']]];

        yield 'from empty' => ['', 'new text', [['insert', 'new text']]];

        yield 'to empty' => ['old text', '', [['delete', 'old text']]];

        yield 'line breaks kept' => ["a\nb", "a\nc", [['equal', "a\n"], ['delete', 'b'], ['insert', 'c']]];
    }

    public function testTooLongToCompare(): void
    {
        $long = implode(' ', range(1, WordDiff::MAX_WORDS + 1));

        $this->assertNull(WordDiff::diff($long, 'short'), 'Beyond the limit, old and new are shown without diff.');
    }

    public function testInvalidUtf8(): void
    {
        $this->assertNull(WordDiff::diff("caf\xE9", 'café'), 'Not compared: old and new are shown instead.');
    }

    public function testTooManyDifferencesToCompare(): void
    {
        $old = implode(' ', array_map(static fn (int $i): string => 'a'.$i, range(1, 600)));
        $new = implode(' ', array_map(static fn (int $i): string => 'b'.$i, range(1, 600)));

        $this->assertNull(WordDiff::diff($old, $new), 'Within the word limit, but too different to compare.');
    }

    public function testLongTextsWithASmallChange(): void
    {
        $words = implode(' ', range(1, 1_500));

        $this->assertSame([['equal', '1 2 '], ['delete', '3'], ['insert', 'three'], ['equal', ' '.implode(' ', range(4, 1_500))]], WordDiff::diff($words, str_replace('1 2 3 4', '1 2 three 4', $words)), 'The common start and end are not compared word by word.');
    }
}
