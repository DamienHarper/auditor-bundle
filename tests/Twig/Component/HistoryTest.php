<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig\Component;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\History\ChangeExtractor;
use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Tests\Fixtures\History\Note;
use DH\AuditorBundle\Twig\Component\History;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
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
        $this->assertNotEmpty($crawler->filter('a[href*="root="]'), 'The actions open with the history they come from.');
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
            // the same sort key as the action: shown right after it
            $notes[] = new Note('tie-'.$i, $item->entries[0]->getSortKey());
        }

        [$actions, $shown, $urls] = $this->walk(['subject' => $this->post(), 'with' => $notes, 'limit' => 1], '/posts/1?tab=activity');
        $this->assertNotEmpty($urls);
        foreach ($urls as $url) {
            $this->assertStringContainsString('tab=activity', $url, 'The query string of the page is kept.');
        }

        $this->assertSame(\count($items), $actions);
        $expected = array_map(strval(...), $notes);
        $this->assertCount(\count($expected), $shown, 'No element is lost nor shown twice.');
        $this->assertEqualsCanonicalizing($expected, $shown);
        $this->assertSame('note:newest', $shown[0]);
        $this->assertSame('note:oldest', $shown[\count($shown) - 1]);
    }

    public function testPagesHoldActions(): void
    {
        // actions of one entry, and actions of several entries (a post and its comments) in between
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post()->setTitle('Why audit?')->setBody('…')->setCreatedAt(new \DateTimeImmutable());
        $entityManager->persist($post);
        $entityManager->flush();
        foreach (['A', 'B', 'C'] as $round) {
            $post->setTitle('Title '.$round);
            $entityManager->flush();
            foreach ([1, 2, 3] as $i) {
                $entityManager->persist(new Comment()->setBody($round.$i)->setAuthor('john')->setCreatedAt(new \DateTimeImmutable())->setPost($post));
            }

            $post->setBody('Body '.$round);
            $entityManager->flush();
        }

        $items = HistoryGrouper::byTransaction(new Reader($this->provider)->createRootQuery(Post::class, (string) $post->getId())->fetch(1_000)->entries);
        $this->assertCount(7, $items);
        $this->assertTrue(array_any($items, static fn (HistoryItem $item): bool => \count($item->entries) > 2), 'An action writes more entries than a page holds actions.');

        foreach ([null, new HistoryGrouper(extractor: new ChangeExtractor())] as $grouper) {
            $pages = [];
            $url = '/posts/'.$post->getId();
            while (null !== $url) {
                $crawler = $this->render(['subject' => $post, 'limit' => 2, 'grouper' => $grouper], Request::create($url));
                $pages[] = $crawler->filter('[data-dh-auditor-action]')->count();
                $older = $crawler->filter('[data-dh-auditor-older]');
                $url = 0 === $older->count() ? null : (string) $older->attr('href');
            }

            $this->assertSame(\count($items), array_sum($pages));
            $this->assertSame(array_fill(0, \count($pages) - 1, 2), \array_slice($pages, 0, -1), 'Every page but the last holds "limit" actions.');
        }
    }

    public function testCursorParameter(): void
    {
        [$actions, , $urls] = $this->walk(['subject' => $this->post(), 'limit' => 1, 'cursorParameter' => 'activity'], '/posts/1', 'activity');

        $this->assertSame(\count(HistoryGrouper::byTransaction($this->entries())), $actions);
        $this->assertNotEmpty($urls);
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('dh_auditor_after', $url);
        }
    }

    public function testCursorOption(): void
    {
        $first = $this->render(['subject' => $this->post(), 'limit' => 1]);
        parse_str((string) parse_url((string) $first->filter('[data-dh-auditor-older]')->attr('href'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['dh_auditor_after'] ?? null);

        $second = $this->render(['subject' => $this->post(), 'limit' => 1, 'after' => $query['dh_auditor_after']]);
        $this->assertSame(
            $this->render(['subject' => $this->post(), 'limit' => 1], Request::create('/posts/1', parameters: $query))->filter('section')->attr('data-dh-auditor-first-cursor'),
            $second->filter('section')->attr('data-dh-auditor-first-cursor'),
            'The second page.',
        );
        $this->assertNotSame($first->filter('section')->attr('data-dh-auditor-first-cursor'), $second->filter('section')->attr('data-dh-auditor-first-cursor'));
    }

    public function testAnInvalidCursorOptionIsAnError(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageIsOrContains('Invalid history cursor "nope".');

        $this->render(['subject' => $this->post(), 'after' => 'nope']);
    }

    public function testAnInvalidPageSizeIsAnError(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageIsOrContains('The page size must be at least 1.');

        $this->render(['subject' => $this->post(), 'limit' => 0]);
    }

    public function testAnInvalidCursorShowsTheFirstPage(): void
    {
        $crawler = $this->render(['subject' => $this->post(), 'limit' => 1, 'with' => [new Note('newest', '9999')]], Request::create('/posts/1?dh_auditor_after=nope'));

        $this->assertCount(1, $crawler->filter('[data-dh-auditor-older]'));
        $this->assertSame('note:newest', mb_trim($crawler->filter('[data-dh-auditor-element]')->text()), 'With the elements of the first page.');
        $this->assertSame(
            $this->render(['subject' => $this->post(), 'limit' => 1])->filter('section')->attr('data-dh-auditor-first-cursor'),
            $crawler->filter('section')->attr('data-dh-auditor-first-cursor'),
        );
    }

    public function testACursorThatIsNotAStringIsIgnored(): void
    {
        $crawler = $this->render(['subject' => $this->post(), 'limit' => 1], Request::create('/posts/1', parameters: ['dh_auditor_after' => ['nope']]));

        $this->assertSame(
            $this->render(['subject' => $this->post(), 'limit' => 1])->filter('section')->attr('data-dh-auditor-first-cursor'),
            $crawler->filter('section')->attr('data-dh-auditor-first-cursor'),
        );
    }

    public function testNoElementPastTheEndOfTheHistory(): void
    {
        $cursor = new Reader($this->provider)->createRootQuery(Post::class, '1')->fetch(1_000)->lastCursor;
        $this->assertIsString($cursor);

        $crawler = $this->render(['subject' => $this->post(), 'with' => [new Note('oldest', '0000'), new Note('newest', '9999')]], Request::create('/posts/1', parameters: ['dh_auditor_after' => $cursor]));

        $this->assertCount(0, $crawler->filter('[data-dh-auditor-action]'));
        $this->assertCount(0, $crawler->filter('[data-dh-auditor-element]'), 'They were shown on the previous pages.');
    }

    public function testRenderedByASubRequest(): void
    {
        $older = $this->render(['subject' => $this->post(), 'limit' => 1])->filter('[data-dh-auditor-older]')->attr('href');
        $this->assertIsString($older);
        parse_str((string) parse_url($older, PHP_URL_QUERY), $query);

        $page = $this->render(['subject' => $this->post(), 'limit' => 1], Request::create($older));
        $fragment = $this->render(['subject' => $this->post(), 'limit' => 1], Request::create($older), Request::create('/_fragment', parameters: ['_path' => 'x']));

        $this->assertSame($page->filter('section')->attr('data-dh-auditor-first-cursor'), $fragment->filter('section')->attr('data-dh-auditor-first-cursor'), 'The cursor of the page the reader sees.');
        $this->assertStringStartsWith('/posts/1?', (string) $fragment->filter('[data-dh-auditor-older]')->attr('href'));
    }

    public function testOlderLinkUnderABasePath(): void
    {
        $request = Request::create('http://localhost/app/index.php/posts/1', server: ['SCRIPT_NAME' => '/app/index.php', 'SCRIPT_FILENAME' => '/var/www/app/index.php']);

        $this->assertStringStartsWith('/app/index.php/posts/1?', (string) $this->render(['subject' => $this->post(), 'limit' => 1], $request)->filter('[data-dh-auditor-older]')->attr('href'));
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
        $this->assertStringNotContainsString('Open in the viewer', $crawler->text());
        $this->assertNull($crawler->filter('section')->attr('data-dh-auditor-first-cursor'));
    }

    public function testNoLinkToTheViewerWhenItIsUnavailable(): void
    {
        $this->assertNotEmpty($this->render(['subject' => $this->post()])->filter('a[href^="/audit"]'));

        // the viewer is unavailable while the auditor is disabled
        $this->provider->getAuditor()->getConfiguration()->disable();
        $crawler = $this->render(['subject' => $this->post(), 'details' => true]);

        $this->assertGreaterThan(0, $crawler->filter('[data-dh-auditor-action]')->count());
        $this->assertCount(0, $crawler->filter('section a'), 'No author, action, object or viewer link.');
        $this->assertStringNotContainsString('Open in the viewer', $crawler->text());
    }

    public function testAnErrorOfTheApplicationIsNotHidden(): void
    {
        $this->provider->getConfiguration()->setReadScope(static function (): never {
            throw new InvalidArgumentException('Broken read scope.');
        });

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageIsOrContains('Broken read scope.');

        $this->render(['subject' => $this->post()]);
    }

    public function testDetails(): void
    {
        $this->assertGreaterThan(0, $this->render(['subject' => $this->post(), 'details' => true])->filter('[data-dh-auditor-details]')->count());
        $this->assertCount(0, $this->render(['subject' => $this->post(), 'details' => 'ROLE_AUDITOR'])->filter('[data-dh-auditor-details]'), 'A role the reader is not granted.');

        /** @var TokenStorageInterface $tokens */
        $tokens = self::getContainer()->get('security.token_storage');
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('alice', null, ['ROLE_AUDITOR']), 'main', ['ROLE_AUDITOR']));
        $this->assertGreaterThan(0, $this->render(['subject' => $this->post(), 'details' => 'ROLE_AUDITOR'])->filter('[data-dh-auditor-details]')->count(), 'A role the reader is granted.');
    }

    public function testGrouper(): void
    {
        $crawler = $this->render(['subject' => $this->post(), 'limit' => 1, 'grouper' => new HistoryGrouper(static fn (Entry $entry): string => 'everything')]);

        $this->assertCount(1, $crawler->filter('[data-dh-auditor-action]'));
        $this->assertCount(0, $crawler->filter('[data-dh-auditor-older]'), 'The page holds the whole action, whatever the limit.');
    }

    public function testWithoutTheAttachedEntities(): void
    {
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post()->setTitle('Why audit?')->setBody('…')->setCreatedAt(new \DateTimeImmutable());
        $entityManager->persist($post);
        $entityManager->flush();
        $entityManager->persist(new Comment()->setBody('Nice')->setAuthor('john')->setCreatedAt(new \DateTimeImmutable())->setPost($post));
        $entityManager->flush();

        $entities = fn (array $props): string => implode(' ', $this->render($props)->filter('[data-dh-auditor-action]')->each(static fn (Crawler $li): string => (string) $li->attr('data-dh-auditor-entities')));

        $this->assertStringContainsString(Comment::class, $entities(['subject' => $post]), 'The creation of the comment is part of the history of its post.');
        $this->assertStringNotContainsString(Comment::class, $entities(['subject' => $post, 'attached' => false]));
        $this->assertStringContainsString(Post::class, $entities(['subject' => $post, 'attached' => false]));
    }

    public function testAnActionOnAnAttachedObjectNamesIt(): void
    {
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post()->setTitle('Why audit?')->setBody('…')->setCreatedAt(new \DateTimeImmutable());
        $entityManager->persist($post);
        $entityManager->flush();

        $comment = new Comment()->setBody('Nice')->setAuthor('john')->setCreatedAt(new \DateTimeImmutable())->setPost($post);
        $entityManager->persist($comment);
        $entityManager->flush();

        $comment->setBody('Edited alone');
        $entityManager->flush();

        $crawler = $this->render(['subject' => $post]);

        $comments = array_values(array_filter($crawler->filter('[data-dh-auditor-action]')->each(static fn (Crawler $li): array => [(string) $li->attr('data-dh-auditor-entities'), $li->text()]), static fn (array $row): bool => Comment::class === $row[0]));
        $this->assertNotEmpty($comments);
        $this->assertStringContainsString('on Comment', $comments[0][1], 'A change of an attached object alone names it.');
        $this->assertStringNotContainsString('on Post', $crawler->filter('section')->text(), 'The object of the history is not named.');
    }

    public function testElementsImplementTheInterface(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageIsOrContains('The elements of the "DhAuditor:History" component implement DH\AuditorBundle\History\HistoryElementInterface, string given.');

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

    private function configureEntities(): void
    {
        $this->provider->getConfiguration()->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Comment::class => ['enabled' => true, 'roots' => ['post']],
            Tag::class => ['enabled' => true],
        ]);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props, ?Request $request = null, ?Request $subRequest = null): Crawler
    {
        /** @var RequestStack $requests */
        $requests = self::getContainer()->get('request_stack');
        while (null !== $requests->pop());

        $requests->push($request ?? Request::create('/posts/1'));
        if ($subRequest instanceof Request) {
            $requests->push($subRequest);
        }

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return new Crawler($twig->createTemplate('{{ component(name, props) }}')->render(['name' => History::NAME, 'props' => $props]));
    }

    /**
     * Follows the "older" links from $url to the last page.
     *
     * @param array<string, mixed> $props
     *
     * @return array{int, list<string>, list<string>} actions, elements shown, URLs followed
     */
    private function walk(array $props, string $url, string $cursorParameter = 'dh_auditor_after'): array
    {
        $actions = 0;
        $shown = [];
        $urls = [];
        for ($page = 0; null !== $url && $page < 50; ++$page) {
            $crawler = $this->render($props, Request::create($url));
            $actions += $crawler->filter('[data-dh-auditor-action]')->count();
            array_push($shown, ...$crawler->filter('[data-dh-auditor-element]')->each(static fn (Crawler $li): string => mb_trim($li->text())));
            $older = $crawler->filter('[data-dh-auditor-older]');
            $url = 0 === $older->count() ? null : (string) $older->attr('href');
            if (null !== $url) {
                $this->assertStringStartsWith(parse_url($urls[0] ?? $url, PHP_URL_PATH).'?', $url);
                $this->assertStringContainsString($cursorParameter.'=', $url);
                $urls[] = $url;
            }
        }

        return [$actions, $shown, $urls];
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
