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
 * The technical details of an action: every value sits in the Old value or New value column, the columns have fixed
 * widths, and an association shows its label.
 *
 * @internal
 */
#[Small]
final class HistoryDetailsTest extends KernelTestCase
{
    private const array AUTHOR = ['class' => Author::class, 'id' => 1, 'label' => 'John', 'table' => 'author'];

    private const array STATUS = ['value' => 'draft', 'label' => 'Draft'];

    /**
     * @param array<string, mixed> $changes
     * @param 'new'|'old'          $column  the column that holds the values
     */
    #[DataProvider('provideEntityLevelDetailsShowOneRowPerFieldInTheirColumnCases')]
    public function testEntityLevelDetailsShowOneRowPerFieldInTheirColumn(string $type, array $changes, string $column, bool $snapshot = false): void
    {
        $rows = $this->fieldRows($this->render([$this->entry($type, $changes, $snapshot)]));
        $other = 'old' === $column ? 'new' : 'old';

        $fields = array_keys($rows);
        sort($fields);
        $this->assertSame(['author', 'status', 'title'], $fields, 'One row per field.');
        $this->assertSame('"John"', $rows['author'][$column], 'The association shows the label of its target, not its JSON summary.');
        $this->assertSame('"First"', $rows['title'][$column], 'A plain field shows its raw value.');
        $this->assertSame('"draft"', $rows['status'][$column], 'A #[DiffLabel] field shows its raw value, as in the details of a field change.');
        foreach ($rows as $field => $row) {
            $this->assertSame('—', $row[$other], \sprintf('The %s column of "%s" is empty.', $other, $field));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string, 3?: bool}>
     */
    public static function provideEntityLevelDetailsShowOneRowPerFieldInTheirColumnCases(): iterable
    {
        $created = ['title' => ['old' => null, 'new' => 'First'], 'author' => ['old' => null, 'new' => self::AUTHOR], 'status' => ['old' => null, 'new' => self::STATUS]];

        yield 'creation' => ['insert', $created, 'new'];

        yield 'snapshot' => ['insert', $created, 'new', true];

        yield 'removal with full_snapshot_on_remove' => ['remove', ['title' => ['old' => 'First', 'new' => null], 'author' => ['old' => self::AUTHOR, 'new' => null], 'status' => ['old' => self::STATUS, 'new' => null]], 'old'];
    }

    public function testCollectionElementsSitInTheColumnOfTheirSide(): void
    {
        $crawler = $this->render([$this->collectionEntry('dissociate'), $this->collectionEntry('associate', 2)]);

        $cells = $crawler->filter('table > tbody > tr')->each(
            static fn (Crawler $row): array => $row->filter('td')->each(static fn (Crawler $cell): string => (string) preg_replace('/\s+/', ' ', mb_trim($cell->text())))
        );

        $values = array_map(static fn (array $row): array => \array_slice($row, 2), $cells);
        $this->assertCount(2, $values);
        $this->assertContains(['− Tag#5 “php”', '—'], $values, 'A removed element sits in the Old value column.');
        $this->assertContains(['—', '+ Tag#5 “php”'], $values, 'An added element sits in the New value column.');
    }

    public function testRawValuesWrapBetweenWords(): void
    {
        $crawler = $this->render([$this->entry('update', ['title' => ['old' => 'First', 'new' => 'Second']])]);

        $classes = explode(' ', (string) $crawler->filter('table code')->first()->attr('class'));
        $this->assertContains('break-words', $classes, 'A long text wraps between its words.');
        $this->assertNotContains('break-all', $classes, 'A long text is not cut in the middle of a word.');
    }

    public function testColumnsHaveFixedWidths(): void
    {
        $crawler = $this->render([$this->entry('update', ['title' => ['old' => 'First', 'new' => 'Second']])]);

        $table = $crawler->filter('table')->first();
        $this->assertStringContainsString('table-fixed', (string) $table->attr('class'));
        $this->assertSame(['w-1/5', 'w-1/5', 'w-[30%]', 'w-[30%]'], $table->filter('colgroup > col')->each(static fn (Crawler $col): string => (string) $col->attr('class')));
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

    /**
     * @return array<string, array{old: string, new: string}> raw field => rendered old and new values
     */
    private function fieldRows(Crawler $crawler): array
    {
        $rows = [];
        foreach ($crawler->filter('tr[data-field]') as $node) {
            $cells = new Crawler($node)->filter('td');
            $rows[(string) $node->getAttribute('data-field')] = ['old' => mb_trim($cells->eq(1)->text()), 'new' => mb_trim($cells->eq(2)->text())];
        }

        return $rows;
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
