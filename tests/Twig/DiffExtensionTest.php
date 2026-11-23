<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\Model\Entry;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\AuditorBundle\Twig\DiffExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
    private const array AUTHOR_1 = ['class' => Author::class, 'id' => 1, 'label' => 'John', 'table' => 'author'];

    private const array AUTHOR_2 = ['class' => Author::class, 'id' => 2, 'label' => 'Jane', 'table' => 'author'];

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $deepDiffs
     * @param list<string>         $expectedRows
     */
    #[DataProvider('provideEntryShowsDeepDiffsDownToTheirLeavesCases')]
    public function testEntryShowsDeepDiffsDownToTheirLeaves(string $type, array $changes, array $deepDiffs, array $expectedRows): void
    {
        $this->assertSame($expectedRows, $this->renderedRows($type, $changes, $deepDiffs));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>, list<string>}>
     */
    public static function provideEntryShowsDeepDiffsDownToTheirLeavesCases(): iterable
    {
        yield 'update of a JSON object whose own key is "new"' => [
            'update',
            ['settings' => ['new' => ['new' => 2, 'old' => 1]]],
            ['settings'],
            ['settings.new 1 → 2'],
        ];

        yield 'insert of a JSON object mixing "new" with other keys' => [
            'insert',
            ['settings' => ['new' => ['new' => 1], 'x' => ['new' => 2]]],
            ['settings'],
            ['settings.new 1', 'settings.x 2'],
        ];

        yield 'removal of a JSON object mixing "old" with other keys' => [
            'remove',
            ['settings' => ['old' => ['old' => 1], 'x' => ['old' => 2]]],
            ['settings'],
            ['settings.old 1', 'settings.x 2'],
        ];

        yield 'nested "new" key' => [
            'update',
            ['settings' => ['a' => ['new' => ['new' => 1]]]],
            ['settings'],
            ['settings.a.new ⌀ → 1'],
        ];

        yield 'to-one association next to a deep diff' => [
            'update',
            ['author' => ['new' => self::AUTHOR_2, 'old' => self::AUTHOR_1], 'settings' => ['theme' => ['new' => 'dark', 'old' => 'light']]],
            ['settings'],
            ['author John → Jane', 'settings.theme light → dark'],
        ];

        yield 'to-one association without marker' => [
            'update',
            ['author' => ['new' => self::AUTHOR_2, 'old' => self::AUTHOR_1]],
            [],
            ['author John → Jane'],
        ];

        // Rows written before the marker existed keep being read by their keys.
        yield 'deep diff without marker' => [
            'update',
            ['settings' => ['theme' => ['new' => 'dark', 'old' => 'light']]],
            [],
            ['settings.theme light → dark'],
        ];

        yield 'JSON object whose own key is "new", without marker' => [
            'update',
            ['settings' => ['new' => ['new' => 2, 'old' => 1]]],
            [],
            ['settings ⌀ → new: 2old: 1'],
        ];
    }

    /**
     * @param array<array-key, mixed> $node
     */
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

    /**
     * @param array<string, mixed> $changes
     * @param list<string>         $deepDiffs fields listed in the "deep_diffs" envelope marker (written only when not empty)
     *
     * @return list<string> one "field old → new" (update) or "field value" (insert, remove) line per rendered row
     */
    private function renderedRows(string $type, array $changes, array $deepDiffs): array
    {
        self::bootKernel();

        $diffs = ['source' => ['id' => 1, 'class' => Post::class, 'label' => 'Post#1', 'table' => 'post'], 'changes' => $changes];
        if ([] !== $deepDiffs) {
            $diffs['deep_diffs'] = $deepDiffs;
        }

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $crawler = new Crawler();
        $crawler->addHtmlContent($twig->render('@DHAuditor/Audit/entry.html.twig', [
            'entry' => Entry::fromArray([
                'id' => 1,
                'schema_version' => 2,
                'type' => $type,
                'object_id' => '1',
                'diffs' => json_encode($diffs, JSON_THROW_ON_ERROR),
                'transaction_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
                'created_at' => new \DateTimeImmutable(),
            ]),
            'entity' => Post::class,
        ]));

        return $crawler->filter('table tr')->each(
            static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', mb_trim($row->text()))
        );
    }
}
