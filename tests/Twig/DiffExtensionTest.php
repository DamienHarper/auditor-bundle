<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\AuditorBundle\Twig\DiffExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * A JSON column is audited as a deep diff whose leaves are {new}, {old} or {new, old}: a JSON object
 * whose own keys are "new"/"old" then looks like a field diff. Entries list their deep diffs in the
 * "deep_diffs" envelope marker; the viewer must read the root of each changeset from it and the
 * nested levels with the leaf rule.
 *
 * @internal
 */
#[Small]
final class DiffExtensionTest extends KernelTestCase
{
    #[DataProvider('provideIsDiffLeafCases')]
    public function testIsDiffLeaf(mixed $node, bool $expected): void
    {
        $this->assertSame($expected, new DiffExtension()->isDiffLeaf($node));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function provideIsDiffLeafCases(): iterable
    {
        yield 'added value' => [['new' => 1], true];

        yield 'removed value' => [['old' => 1], true];

        yield 'changed value' => [['new' => 2, 'old' => 1], true];

        yield 'value set to null' => [['new' => null, 'old' => 1], true];

        yield 'object whose own key is "new"' => [['new' => ['new' => 1]], false];

        yield 'object with other keys' => [['new' => ['new' => 1], 'x' => ['new' => 2]], false];

        yield 'empty node' => [[], false];

        yield 'scalar' => ['new', false];

        yield 'null' => [null, false];
    }

    public function testFunctionIsRegistered(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $this->assertSame('yes', $twig->createTemplate("{{ dh_auditor_is_diff_leaf({new: 1}) ? 'yes' : 'no' }}")->render());
    }

    public function testWordDiff(): void
    {
        $extension = new DiffExtension();

        $this->assertSame([['equal', 'a '], ['delete', 'b'], ['insert', 'c']], $extension->wordDiff('a b', 'a c'));
        $this->assertSame([['insert', 'text']], $extension->wordDiff(null, 'text'), 'From no value.');
        $this->assertNull($extension->wordDiff(['a'], 'text'), 'Not a text.');
        $this->assertNull($extension->wordDiff('text', 12), 'Not a text.');
    }

    #[DataProvider('provideTextChangeCases')]
    public function testTextChange(mixed $old, mixed $new, ?string $expectedMode): void
    {
        $change = new DiffExtension()->textChange($old, $new);

        $this->assertSame($expectedMode, $change['mode'] ?? null);
    }

    /**
     * @return iterable<string, array{mixed, mixed, null|string}>
     */
    public static function provideTextChangeCases(): iterable
    {
        yield 'most of the text kept: the changed words inline' => ['Aut adipisci necessitatibus laudantium mollitia.', 'Aut adipisci necessitatibus laudantium mollitia - Revised Edition', 'inline'];

        yield 'short value replaced: old → new' => ['draft', 'published', 'replace'];

        yield 'long text rewritten: old above new' => ['Sit aliquid sequi quia distinctio odit quos consequatur debitis sunt provident.', 'Rerum quia fuga velit doloribus ea qui non dolores et quasi veritatis eaque.', 'stack'];

        yield 'from null: not a text change' => [null, 'text', null];

        yield 'to null: not a text change' => ['text', null, null];

        yield 'not texts' => [1, 2, null];
    }

    public function testTextChangeKeepsTheRunsOfTheWordDiff(): void
    {
        $change = new DiffExtension()->textChange('a b', 'a c');

        $this->assertSame(['mode' => 'replace', 'runs' => [['equal', 'a '], ['delete', 'b'], ['insert', 'c']]], $change, 'Short values replaced, half of their words changed.');
    }

    #[DataProvider('provideValueTextCases')]
    public function testValueText(mixed $value, string $expected): void
    {
        $this->assertSame($expected, new DiffExtension()->valueText($value));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideValueTextCases(): iterable
    {
        yield 'a text: no quotes' => ['published', 'published'];

        yield 'a text with inner spaces and accents' => ['Brouillon publié', 'Brouillon publié'];

        yield 'an empty text' => ['', '""'];

        yield 'a leading space' => [' draft', '" draft"'];

        yield 'a trailing line break' => ["draft\n", "\"draft\n\""];

        yield 'a text that looks like a number' => ['42', '"42"'];

        yield 'a text that looks like a decimal' => ['-1.5e3', '"-1.5e3"'];

        yield 'a text that looks like a boolean' => ['False', '"False"'];

        yield 'a text that looks like null' => ['null', '"null"'];

        yield 'a text that only starts with a number' => ['42 posts', '42 posts'];

        yield 'null' => [null, 'null'];

        yield 'a number' => [42, '42'];

        yield 'a boolean' => [false, 'false'];

        yield 'an array, as JSON' => [['label' => 'Violet', 'url' => 'https://example.com/é'], '{"label":"Violet","url":"https://example.com/é"}'];
    }
}
