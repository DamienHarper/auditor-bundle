<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * The technical details of an action that concerns the whole entity (creation, snapshot, removal with
 * full_snapshot_on_remove) show the label of an association, as the details of a field change do.
 *
 * @internal
 */
#[Small]
final class HistoryDetailsTest extends KernelTestCase
{
    private const array AUTHOR = ['class' => Author::class, 'id' => 1, 'label' => 'John', 'table' => 'author'];

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('provideEntityLevelDetailsShowTheLabelOfAnAssociationCases')]
    public function testEntityLevelDetailsShowTheLabelOfAnAssociation(string $type, array $changes, bool $snapshot = false): void
    {
        $values = $this->renderedValues($type, $changes, $snapshot);

        $this->assertSame('"John"', $values['author'], 'The association shows the label of its target, not its JSON summary.');
        $this->assertSame('"First"', $values['title'], 'A plain field shows its raw value.');
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2?: bool}>
     */
    public static function provideEntityLevelDetailsShowTheLabelOfAnAssociationCases(): iterable
    {
        yield 'creation' => ['insert', ['title' => ['old' => null, 'new' => 'First'], 'author' => ['old' => null, 'new' => self::AUTHOR]]];

        yield 'snapshot' => ['insert', ['title' => ['old' => null, 'new' => 'First'], 'author' => ['old' => null, 'new' => self::AUTHOR]], true];

        yield 'removal with full_snapshot_on_remove' => ['remove', ['title' => ['old' => 'First', 'new' => null], 'author' => ['old' => self::AUTHOR, 'new' => null]]];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, string> raw field => rendered value
     */
    private function renderedValues(string $type, array $changes, bool $snapshot): array
    {
        self::bootKernel();

        $diffs = ['source' => ['id' => 1, 'class' => Post::class, 'label' => 'First', 'table' => 'post'], 'changes' => $changes];
        if ($snapshot) {
            $diffs['snapshot'] = true;
        }

        $entry = Entry::fromArray([
            'id' => 1,
            'schema_version' => 2,
            'type' => $type,
            'object_id' => '1',
            'diffs' => json_encode($diffs, JSON_THROW_ON_ERROR),
            'transaction_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
            'created_at' => new \DateTimeImmutable(),
            'entity' => Post::class,
        ]);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $crawler = new Crawler();
        $crawler->addHtmlContent($twig->render('@DHAuditor/History/_details.html.twig', ['item' => new HistoryItem('1', [$entry])]));

        $values = [];
        foreach ($crawler->filter('dl dt') as $index => $term) {
            $field = new Crawler($term)->filter('span.font-mono')->text();
            $values[$field] = mb_trim($crawler->filter('dl dd')->eq($index)->text());
        }

        return $values;
    }
}
