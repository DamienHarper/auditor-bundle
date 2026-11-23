<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\History\ChangeExtractor;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\Auditor\User\User;
use DH\AuditorBundle\Tests\Fixtures\History\NoopReadScope;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use DH\AuditorBundle\Viewer\HistoryFilters;
use DH\AuditorBundle\Viewer\ObjectHistory;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @internal
 */
#[Small]
final class ObjectHistoryTest extends TestCase
{
    use BlogSchemaSetupTrait;

    public function testAttachedEntities(): void
    {
        $postId = $this->createCommentedPost();
        $history = new ObjectHistory(new Reader($this->provider));

        $entities = static fn (array $entries): array => array_values(array_unique(array_map(ChangeExtractor::entityOf(...), $entries)));

        $page = $history->page(Post::class, $postId, new HistoryFilters(), null, 100);
        $this->assertContains(Comment::class, $entities($page->entries), 'The comments of the post are part of its history.');
        $this->assertContains(Post::class, $entities($page->entries));

        $page = $history->page(Post::class, $postId, new HistoryFilters(attached: false), null, 100);
        $this->assertSame([Post::class], $entities($page->entries), 'Without the attached entities.');

        $this->assertTrue($history->hasAttached());
        $this->assertSame(['post_audit', 'comment_audit'], $history->tables(Post::class, true));
        $this->assertSame(['post_audit'], $history->tables(Post::class, false));
        $this->assertGreaterThan($history->count(Post::class, $postId, false), $history->count(Post::class, $postId, true));
    }

    public function testAuthorsAndCreation(): void
    {
        $postId = $this->createCommentedPost();
        $history = new ObjectHistory(new Reader($this->provider));

        $this->assertSame(['users' => [['id' => '7', 'name' => 'alice']], 'anonymous' => false], $history->authors(Post::class, $postId, true));

        $creation = $history->creation(Post::class, $postId);
        $this->assertInstanceOf(Entry::class, $creation);
        $this->assertSame('insert', $creation->type);
    }

    public function testAuthorsAreNotCachedWithAReadScope(): void
    {
        $postId = $this->createCommentedPost();
        $cache = new ActivityGraphProvider(7, 'bottom', true, 300, new ArrayAdapter());
        $history = new ObjectHistory(new Reader($this->provider), $cache);

        $this->assertSame(['alice'], array_column($history->authors(Post::class, $postId, true)['users'], 'name'));
        $this->provider->getAuditor()->getConfiguration()->setUserProvider(static fn (): User => new User('8', 'bob'));
        $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager()->find(Post::class, (int) $postId)?->setTitle('By bob');
        $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager()->flush();

        $this->assertSame(['alice'], array_column($history->authors(Post::class, $postId, true)['users'], 'name'), 'Cached.');

        $this->provider->getConfiguration()->setReadScope(new NoopReadScope());
        $this->assertSame(['alice', 'bob'], array_column($history->authors(Post::class, $postId, true)['users'], 'name'), 'A read scope depends on the reader: not cached.');
    }

    public function testAnonymousAuthors(): void
    {
        $postId = $this->createCommentedPost();
        $this->provider->getAuditor()->getConfiguration()->setUserProvider(static fn (): ?User => null);
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = $entityManager->find(Post::class, (int) $postId);
        $this->assertInstanceOf(Post::class, $post);
        $post->setTitle('Changed without user');
        $entityManager->flush();

        $history = new ObjectHistory(new Reader($this->provider));

        $this->assertTrue($history->authors(Post::class, $postId, true)['anonymous']);
        $this->assertSame(['alice'], array_column($history->authors(Post::class, $postId, true)['users'], 'name'));

        $page = $history->page(Post::class, $postId, new HistoryFilters(who: HistoryFilters::ANONYMOUS), null, 100);
        $this->assertCount(1, $page->entries, 'The change made without user.');
        $this->assertNull($page->entries[0]->userId);
    }

    /**
     * A post written by alice, then commented.
     */
    private function createCommentedPost(): string
    {
        $this->provider->getAuditor()->getConfiguration()->setUserProvider(static fn (): User => new User('7', 'alice'));
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post()->setTitle('Why audit?')->setBody('…')->setCreatedAt(new \DateTimeImmutable());
        $entityManager->persist($post);
        $entityManager->flush();

        $comment = new Comment()->setBody('Nice')->setAuthor('john')->setCreatedAt(new \DateTimeImmutable())->setPost($post);
        $entityManager->persist($comment);
        $entityManager->flush();

        return (string) $post->getId();
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
}
