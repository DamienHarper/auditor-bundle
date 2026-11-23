<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig\Component;

use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Tests\Fixtures\History\Note;
use DH\AuditorBundle\Twig\Component\History;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * @internal
 */
#[Small]
final class HistoryTest extends KernelTestCase
{
    use BlogSchemaSetupTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->createAndInitDoctrineProvider();
        $this->configureEntities();
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
        $this->setupEntities();
    }

    public function testActionsOfTheSubject(): void
    {
        $post = $this->post();
        $entries = $this->entries();

        $crawler = $this->render(['subject' => $post]);

        $section = $crawler->filter('section[data-dh-auditor-history]');
        $this->assertCount(1, $section);
        $this->assertSame(Post::class.':1', $section->attr('data-dh-auditor-history'));
        $this->assertNotEmpty($section->attr('data-dh-auditor-first-cursor'), 'The position of the most recent entry is exposed.');
        $this->assertCount(\count(HistoryGrouper::byTransaction($entries)), $crawler->filter('[data-dh-auditor-action]'));
        $this->assertCount(0, $crawler->filter('[data-dh-auditor-details]'), 'No technical details by default.');
        $this->assertCount(0, $crawler->filter('[data-dh-auditor-older]'), 'One page holds the whole history.');
        $this->assertStringContainsString('Open in the viewer', $crawler->text());
    }

    public function testElementsAreMixedBySortKey(): void
    {
        $entries = $this->entries();
        $this->assertGreaterThan(1, \count(HistoryGrouper::byTransaction($entries)));
        $items = HistoryGrouper::byTransaction($entries);

        $crawler = $this->render([
            'subject' => $this->post(),
            'with' => [
                new Note('oldest', '0000'),
                new Note('between', $items[1]->entries[0]->getSortKey().'~'),
                new Note('newest', '9999'),
            ],
        ]);

        $lines = $crawler->filter('ol > li')->each(static fn (Crawler $li): string => null !== $li->attr('data-dh-auditor-element') ? mb_trim($li->text()) : 'action');
        $this->assertSame('note:newest', $lines[0]);
        $this->assertSame('action', $lines[1]);
        $this->assertSame('note:between', $lines[2], 'Right before the action it is newer than.');
        $this->assertSame('action', $lines[3]);
        $this->assertSame('note:oldest', $lines[\count($lines) - 1]);
    }

    public function testEveryElementIsShownOnceAcrossThePages(): void
    {
        $items = HistoryGrouper::byTransaction($this->entries());
        $this->assertGreaterThan(2, \count($items));

        $notes = [new Note('newest', '9999'), new Note('oldest', '0000')];
        foreach ($items as $i => $item) {
            // one note right before each action, one right after
            $notes[] = new Note('before-'.$i, $item->entries[0]->getSortKey().'~');
            $notes[] = new Note('after-'.$i, mb_substr($item->entries[\count($item->entries) - 1]->getSortKey(), 0, -1));
        }

        $shown = [];
        $actions = 0;
        $url = '/posts/1?tab=activity';
        for ($page = 0; null !== $url && $page < 50; ++$page) {
            $crawler = $this->render(['subject' => $this->post(), 'with' => $notes, 'limit' => 1], Request::create($url));
            $actions += $crawler->filter('[data-dh-auditor-action]')->count();
            array_push($shown, ...$crawler->filter('[data-dh-auditor-element]')->each(static fn (Crawler $li): string => mb_trim($li->text())));
            $older = $crawler->filter('[data-dh-auditor-older]');
            $url = 0 === $older->count() ? null : $older->attr('href');
            if (null !== $url) {
                $this->assertStringStartsWith('/posts/1?', $url);
                $this->assertStringContainsString('tab=activity', $url, 'The query string of the page is kept.');
                $this->assertStringContainsString('dh_auditor_after=', $url);
            }
        }

        $this->assertSame(\count($items), $actions);
        $expected = array_map(strval(...), $notes);
        $this->assertCount(\count($expected), $shown, 'No element is lost nor shown twice.');
        $this->assertEqualsCanonicalizing($expected, $shown);
        $this->assertSame('note:newest', $shown[0]);
        $this->assertSame('note:oldest', $shown[\count($shown) - 1]);
    }

    public function testAnInvalidCursorShowsTheFirstPage(): void
    {
        $crawler = $this->render(['subject' => $this->post(), 'limit' => 1], Request::create('/posts/1?dh_auditor_after=nope'));

        $this->assertCount(1, $crawler->filter('[data-dh-auditor-older]'));
        $this->assertSame(
            $this->render(['subject' => $this->post(), 'limit' => 1])->filter('section')->attr('data-dh-auditor-first-cursor'),
            $crawler->filter('section')->attr('data-dh-auditor-first-cursor'),
        );
    }

    public function testElementsAloneWithoutHistory(): void
    {
        $notes = [new Note('a', '0001'), new Note('b', '0002')];

        $crawler = $this->render(['subject' => new Post(), 'with' => $notes]);
        $this->assertSame(['note:b', 'note:a'], $crawler->filter('[data-dh-auditor-element]')->each(static fn (Crawler $li): string => mb_trim($li->text())), 'Not persisted yet: the elements, most recent first.');
        $this->assertNull($crawler->filter('section')->attr('data-dh-auditor-first-cursor'));
        $this->assertStringNotContainsString('Open in the viewer', $crawler->text());

        $crawler = $this->render(['subject' => new \stdClass()]);
        $this->assertStringContainsString('No activity yet.', $crawler->text(), 'Not an entity.');
    }

    public function testNoHistoryWhenItCannotBeViewed(): void
    {
        $configuration = $this->provider->getAuditor()->getConfiguration();
        $configuration->setRoleChecker(static fn (): bool => true);
        $this->assertGreaterThan(0, $this->render(['subject' => $this->post()])->filter('[data-dh-auditor-action]')->count());

        $configuration->setRoleChecker(static fn (): bool => false);
        $crawler = $this->render(['subject' => $this->post(), 'with' => [new Note('a', '0001')]]);

        $this->assertCount(0, $crawler->filter('[data-dh-auditor-action]'));
        $this->assertCount(1, $crawler->filter('[data-dh-auditor-element]'), 'The elements of the application are still shown.');
        $this->assertNull($crawler->filter('section')->attr('data-dh-auditor-first-cursor'));
    }

    public function testDetails(): void
    {
        $this->assertGreaterThan(0, $this->render(['subject' => $this->post(), 'details' => true])->filter('[data-dh-auditor-details]')->count());
        $this->assertCount(0, $this->render(['subject' => $this->post(), 'details' => 'ROLE_AUDITOR'])->filter('[data-dh-auditor-details]'), 'A role the reader is not granted.');
    }

    public function testGrouper(): void
    {
        $crawler = $this->render(['subject' => $this->post(), 'grouper' => new HistoryGrouper(static fn (Entry $entry): string => 'everything')]);

        $this->assertCount(1, $crawler->filter('[data-dh-auditor-action]'));
    }

    public function testWithoutTheAttachedEntities(): void
    {
        $all = $this->render(['subject' => $this->post()])->filter('[data-dh-auditor-action]')->count();
        $own = $this->render(['subject' => $this->post(), 'attached' => false])->filter('[data-dh-auditor-action]')->count();

        $this->assertCount($own, HistoryGrouper::byTransaction($this->entries(attached: false)));
        $this->assertLessThanOrEqual($all, $own);
    }

    public function testElementsImplementTheInterface(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageIsOrContains('HistoryElementInterface');

        $this->render(['subject' => $this->post(), 'with' => ['a comment']]);
    }

    public function testElementBlock(): void
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->createTemplate(<<<'TWIG'
            <twig:DhAuditor:History :subject="post" :with="notes">
                <twig:block name="element"><em>{{ element.name }}</em></twig:block>
            </twig:DhAuditor:History>
            TWIG)->render(['post' => $this->post(), 'notes' => [new Note('custom', '9999')]]);

        $this->assertSame('custom', new Crawler($html)->filter('[data-dh-auditor-element] em')->text());
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props, ?Request $request = null): Crawler
    {
        /** @var RequestStack $requests */
        $requests = self::getContainer()->get('request_stack');
        while (null !== $requests->pop());

        $requests->push($request ?? Request::create('/posts/1'));

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return new Crawler($twig->createTemplate('{{ component(name, props) }}')->render(['name' => History::NAME, 'props' => $props]));
    }

    private function post(): Post
    {
        $post = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager()->find(Post::class, 1);
        $this->assertInstanceOf(Post::class, $post);

        return $post;
    }

    /**
     * @return list<Entry>
     */
    private function entries(bool $attached = true): array
    {
        return new Reader($this->provider)->createRootQuery(Post::class, '1', $attached ? [] : ['include' => [Post::class]])->fetch(1_000)->entries;
    }
}
