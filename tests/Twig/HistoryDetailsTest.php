<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The technical details of an action: one Change column with fixed widths, whose rendering depends on the values
 * (changed words inline, old → new, old above new), one row per field for a creation or removal, and the label of an
 * association.
 *
 * @internal
 */
#[Small]
final class HistoryDetailsTest extends KernelTestCase
{
    private const array AUTHOR = ['class' => Author::class, 'id' => 1, 'label' => 'John', 'table' => 'author'];

    private const array STATUS = ['value' => 'draft', 'label' => 'Draft'];

    public function testColumnsHaveFixedWidths(): void
    {
        $table = $this->render([$this->entry('update', ['title' => ['old' => 'First', 'new' => 'Second']])])->filter('table')->first();

        $this->assertStringContainsString('table-fixed', (string) $table->attr('class'));
        $this->assertStringContainsString('dark:text-slate-200', (string) $table->attr('class'), 'The values stay readable in dark mode.');
        $this->assertSame(['w-1/5', 'w-1/5', 'w-3/5'], $table->filter('colgroup > col')->each(static fn (Crawler $col): string => (string) $col->attr('class')));
        $this->assertSame(['Object', 'Field', 'Change'], $table->filter('thead th')->each(static fn (Crawler $th): string => mb_trim($th->text())));
    }

    public function testChangesAreStackedOnANarrowScreen(): void
    {
        $crawler = $this->render([
            $this->entry('update', ['title' => ['old' => 'First', 'new' => 'Second']]),
            $this->entry('remove', ['title' => ['old' => 'First', 'new' => null]]),
        ]);

        $classes = static fn (Crawler $node): array => explode(' ', (string) $node->attr('class'));
        $table = $crawler->filter('table')->first();
        $this->assertContains('block', $classes($table), 'On a narrow screen, the details are not a table.');
        $this->assertContains('sm:table', $classes($table), 'From the sm breakpoint, they are.');
        $this->assertContains('hidden', $classes($table->filter('thead')), 'The column headers only make sense for columns.');
        foreach ($table->filter('table > tbody > tr, tr[data-field]') as $row) {
            $this->assertContains('sm:table-row', $classes(new Crawler($row)));
            foreach (new Crawler($row)->children() as $cell) {
                $this->assertSame(['block', 'sm:table-cell'], array_values(array_intersect(['block', 'sm:table-cell'], $classes(new Crawler($cell)))), 'Each cell is stacked on a narrow screen.');
            }
        }
    }

    /**
     * @param array{old: mixed, new: mixed} $change
     * @param list<string>                  $deleted
     * @param list<string>                  $inserted
     */
    #[DataProvider('provideFieldChangesShowInOneCellCases')]
    public function testFieldChangesShowInOneCell(array $change, string $text, array $deleted, array $inserted): void
    {
        $cell = $this->changeCell($this->render([$this->entry('update', ['title' => $change])]));

        $this->assertSame($text, $this->text($cell), 'The whole change sits in the Change column.');
        $this->assertSame($deleted, $cell->filter('del')->each(fn (Crawler $node): string => $this->text($node)));
        $this->assertSame($inserted, $cell->filter('ins')->each(fn (Crawler $node): string => $this->text($node)));
    }

    /**
     * @return iterable<string, array{array{old: mixed, new: mixed}, string, list<string>, list<string>}>
     */
    public static function provideFieldChangesShowInOneCellCases(): iterable
    {
        yield 'most of a text kept: the changed words inline' => [
            ['old' => 'Aut adipisci necessitatibus laudantium mollitia.', 'new' => 'Aut adipisci necessitatibus laudantium mollitia - Revised Edition'],
            'Aut adipisci necessitatibus laudantium mollitia. - Revised Edition',
            ['.'],
            ['- Revised Edition'],
        ];

        yield 'short values replaced: old → new' => [
            ['old' => 'draft', 'new' => 'published'],
            'draft → published',
            ['draft'],
            ['published'],
        ];

        yield 'a text rewritten: old above new' => [
            ['old' => 'Sit aliquid sequi quia distinctio odit quos consequatur debitis sunt provident.', 'new' => 'Rerum quia fuga velit doloribus ea qui non dolores et quasi veritatis eaque.'],
            'Sit aliquid sequi quia distinctio odit quos consequatur debitis sunt provident. Rerum quia fuga velit doloribus ea qui non dolores et quasi veritatis eaque.',
            ['Sit aliquid sequi quia distinctio odit quos consequatur debitis sunt provident.'],
            ['Rerum quia fuga velit doloribus ea qui non dolores et quasi veritatis eaque.'],
        ];

        yield 'a value set from null' => [['old' => null, 'new' => 'First'], 'null → First', [], ['First']];

        yield 'a value cleared' => [['old' => 'First', 'new' => null], 'First → null', ['First'], []];

        yield 'an association: its labels' => [['old' => null, 'new' => self::AUTHOR], 'null → John', [], ['John']];

        yield 'numbers' => [['old' => 1, 'new' => 2], '1 → 2', ['1'], ['2']];

        yield 'texts that need their quotes' => [['old' => '', 'new' => '42'], '"" → "42"', ['""'], ['"42"']];

        yield 'most of a text kept, a trailing space added: between quotes' => [
            ['old' => 'Aut adipisci necessitatibus laudantium mollitia.', 'new' => 'Aut adipisci necessitatibus laudantium mollitia. '],
            '"Aut adipisci necessitatibus laudantium mollitia. "',
            [],
            [''], // the inserted space, trimmed by text()
        ];
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('provideEntityLevelDetailsShowOneRowPerFieldCases')]
    public function testEntityLevelDetailsShowOneRowPerField(string $type, array $changes, bool $snapshot = false): void
    {
        $crawler = $this->render([$this->entry($type, $changes, $snapshot)]);

        $rows = [];
        foreach ($crawler->filter('tr[data-field]') as $node) {
            $cells = new Crawler($node)->filter('td');
            $this->assertCount(2, $cells, 'A field and its value.');
            $rows[(string) $node->getAttribute('data-field')] = $this->text($cells->eq(1));
        }

        ksort($rows);
        $this->assertSame(['author' => 'John', 'status' => 'draft', 'title' => 'First'], $rows, 'The label of an association, the raw value of the other fields.');
        $this->assertCount(0, $crawler->filter('tr[data-field] del, tr[data-field] ins'), 'The values of a creation or removal are not marked.');
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2?: bool}>
     */
    public static function provideEntityLevelDetailsShowOneRowPerFieldCases(): iterable
    {
        $created = ['title' => ['old' => null, 'new' => 'First'], 'author' => ['old' => null, 'new' => self::AUTHOR], 'status' => ['old' => null, 'new' => self::STATUS]];

        yield 'creation' => ['insert', $created];

        yield 'snapshot' => ['insert', $created, true];

        yield 'removal with full_snapshot_on_remove' => ['remove', ['title' => ['old' => 'First', 'new' => null], 'author' => ['old' => self::AUTHOR, 'new' => null], 'status' => ['old' => self::STATUS, 'new' => null]]];
    }

    public function testCollectionElementsShowInTheChangeColumn(): void
    {
        $crawler = $this->render([$this->collectionEntry('dissociate'), $this->collectionEntry('associate', 2)]);

        $cells = $crawler->filter('table > tbody > tr')->each(fn (Crawler $row): array => $row->filter('td')->each(fn (Crawler $cell): string => $this->text($cell)));
        $changes = array_map(static fn (array $row): string => $row[2], $cells);
        sort($changes);

        $this->assertSame(['+ Tag#5 “php”', '− Tag#5 “php”'], $changes);
    }

    public function testTextsKeepTheirLineBreaks(): void
    {
        $text = "First paragraph.\n\nSecond paragraph.";

        foreach ([$this->entry('update', ['title' => ['old' => null, 'new' => $text]]), $this->entry('insert', ['title' => ['old' => null, 'new' => $text]])] as $entry) {
            $code = $this->render([$entry])->filter('table code')->last();

            $this->assertSame($text, $code->text(null, false), 'A text is shown with its line breaks, not as escaped JSON.');
            $this->assertStringContainsString('whitespace-pre-wrap', (string) $code->attr('class'));
        }
    }

    public function testRawValuesWrapBetweenWords(): void
    {
        $crawler = $this->render([$this->entry('insert', ['title' => ['old' => null, 'new' => 'First']])]);

        $classes = explode(' ', (string) $crawler->filter('table code')->first()->attr('class'));
        $this->assertContains('break-words', $classes, 'A long text wraps between its words.');
        $this->assertNotContains('break-all', $classes, 'A long text is not cut in the middle of a word.');
    }

    /**
     * @param list<Entry> $entries
     */
    private function render(array $entries): Crawler
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $crawler = new Crawler();
        $crawler->addHtmlContent($twig->render('@DHAuditor/History/_details.html.twig', ['item' => new HistoryItem('1', $entries)]));

        return $crawler;
    }

    private function changeCell(Crawler $crawler): Crawler
    {
        return $crawler->filter('table > tbody > tr')->first()->filter('td')->eq(2);
    }

    private function text(Crawler $node): string
    {
        return (string) preg_replace('/\s+/', ' ', mb_trim($node->text()));
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function entry(string $type, array $changes, bool $snapshot = false): Entry
    {
        $diffs = ['source' => ['id' => 1, 'class' => Post::class, 'label' => 'First', 'table' => 'post'], 'changes' => $changes];
        if ($snapshot) {
            $diffs['snapshot'] = true;
        }

        return $this->fromDiffs($type, $diffs);
    }

    private function collectionEntry(string $type, int $id = 1): Entry
    {
        return $this->fromDiffs($type, [
            'source' => ['id' => 1, 'class' => Post::class, 'label' => 'First', 'table' => 'post', 'field' => 'tags'],
            'target' => ['id' => 5, 'class' => Tag::class, 'label' => 'php', 'table' => 'tag', 'field' => 'posts'],
            'is_owning_side' => true,
            'join_table' => 'post__tag',
        ], $id);
    }

    /**
     * @param array<string, mixed> $diffs
     */
    private function fromDiffs(string $type, array $diffs, int $id = 1): Entry
    {
        return Entry::fromArray([
            'id' => $id,
            'schema_version' => 2,
            'type' => $type,
            'object_id' => '1',
            'diffs' => json_encode($diffs, JSON_THROW_ON_ERROR),
            'transaction_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
            'created_at' => new \DateTimeImmutable(),
            'entity' => Post::class,
        ]);
    }
}
