<?php

namespace DH\DoctrineAuditBundle\Tests\Reader;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Reader\AuditEntry;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Tag;
use Pagerfanta\Pagerfanta;

/**
 * @covers \DH\DoctrineAuditBundle\Annotation\AnnotationLoader
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLoggerChain
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Helper\UpdateHelper
 * @covers \DH\DoctrineAuditBundle\Manager\AuditManager
 * @covers \DH\DoctrineAuditBundle\Manager\AuditTransaction
 * @covers \DH\DoctrineAuditBundle\Reader\AuditEntry
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 * @covers \DH\DoctrineAuditBundle\User\User
 *
 * @internal
 */
final class AuditReaderTest extends CoreTest
{
    public function testGetAuditConfiguration(): void
    {
        $reader = $this->getReader();

        static::assertInstanceOf(AuditConfiguration::class, $reader->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
    }

    public function testFilterIsNullByDefault(): void
    {
        $reader = $this->getReader();

        static::assertNull($reader->getFilter(), 'filter is null by default.');
    }

    public function testFilterCanOnlyBePartOfAllowedValues(): void
    {
        $reader = $this->getReader();

        $reader->filterBy('UNKNOWN');
        static::assertNull($reader->getFilter(), 'filter is null when AuditReader::filterBy() parameter is not an allowed value.');

        $reader->filterBy(AuditReader::ASSOCIATE);
        static::assertSame(AuditReader::ASSOCIATE, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');

        $reader->filterBy(AuditReader::DISSOCIATE);
        static::assertSame(AuditReader::DISSOCIATE, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');

        $reader->filterBy(AuditReader::INSERT);
        static::assertSame(AuditReader::INSERT, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');

        $reader->filterBy(AuditReader::REMOVE);
        static::assertSame(AuditReader::REMOVE, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');

        $reader->filterBy(AuditReader::UPDATE);
        static::assertSame(AuditReader::UPDATE, $reader->getFilter(), 'filter is not null when AuditReader::filterBy() parameter is an allowed value.');
    }

    public function testGetEntityTableName(): void
    {
        $entities = [
            Post::class => null,
            Comment::class => null,
        ];

        $configuration = $this->createAuditConfiguration([
            'entities' => $entities,
        ]);

        $reader = $this->getReader($configuration);

        static::assertSame('post', $reader->getEntityTableName(Post::class), 'tablename is ok.');
        static::assertSame('comment', $reader->getEntityTableName(Comment::class), 'tablename is ok.');
    }

    public function testGetEntityTableAuditName(): void
    {
        $entities = [
            Post::class => null,
            Comment::class => null,
        ];

        $configuration = $this->createAuditConfiguration([
            'entities' => $entities,
        ]);

        $reader = $this->getReader($configuration);

        static::assertSame('post_audit', $reader->getEntityAuditTableName(Post::class), 'tablename is ok.');
        static::assertSame('comment_audit', $reader->getEntityAuditTableName(Comment::class), 'tablename is ok.');
    }

    /**
     * @depends testGetEntityTableName
     */
    public function testGetEntities(): void
    {
        $entities = [
            Post::class => null,
            Comment::class => null,
        ];

        $expected = array_combine(
            array_keys($entities),
            ['post', 'comment']
        );
        ksort($expected);

        $configuration = $this->createAuditConfiguration([
            'entities' => $entities,
        ]);

        $reader = $this->getReader($configuration);

        static::assertSame($expected, $reader->getEntities(), 'entities are sorted.');
    }

    public function testGetAudits(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 1, 50);

        $i = 0;
        static::assertCount(5, $audits, 'result count is ok.');
        static::assertSame(AuditReader::REMOVE, $audits[$i++]->getType(), 'entry'.$i.' is a remove operation.');
        static::assertSame(AuditReader::UPDATE, $audits[$i++]->getType(), 'entry'.$i.' is an update operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Post::class, null, 1, 50);

        $i = 0;
        static::assertCount(15, $audits, 'result count is ok.');
        static::assertSame(AuditReader::UPDATE, $audits[$i++]->getType(), 'entry'.$i.' is an update operation.');
        static::assertSame(AuditReader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is a dissociate operation.');
        static::assertSame(AuditReader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is a dissociate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Comment::class, null, 1, 50);

        $i = 0;
        static::assertCount(3, $audits, 'result count is ok.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Tag::class, null, 1, 50);

        $i = 0;
//        $this->assertCount(14, $audits, 'result count is ok.');
        static::assertCount(15, $audits, 'result count is ok.');
//        $this->assertCount(12, $audits, 'result count is ok.');
        static::assertSame(AuditReader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is a dissociate operation.');
        static::assertSame(AuditReader::DISSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is a dissociate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::ASSOCIATE, $audits[$i++]->getType(), 'entry'.$i.' is an associate operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        static::assertSame(AuditReader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');

        $this->expectException(\InvalidArgumentException::class);
        $reader->getAudits(Post::class, null, 0, 50);
        $reader->getAudits(Post::class, null, -1, 50);
    }

    public function testGetAuditsPager(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        /** @var AuditEntry[] $audits */
        $pager = $reader->getAuditsPager(Author::class, null, 1, 3);

        static::assertInstanceOf(Pagerfanta::class, $pager, 'pager is a Pagerfanta instance.');
        static::assertTrue($pager->haveToPaginate(), 'pager has to paginate.');
    }

    public function testGetAuditsCount(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        /** @var AuditEntry[] $audits */
        $count = $reader->getAuditsCount(Author::class, null);

        static::assertSame(5, $count, 'count is ok.');
    }

    /**
     * @depends testGetAudits
     */
    public function testGetAuditsHonorsId(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Author::class, 1, 1, 50);

        static::assertCount(2, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Post::class, 1, 1, 50);

        static::assertCount(3, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Comment::class, 1, 1, 50);

        static::assertCount(1, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Post::class, 0, 1, 50);
        static::assertSame([], $audits, 'no result when id is invalid.');
    }

    /**
     * @depends testGetAudits
     */
    public function testGetAuditsHonorsPageSize(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 1, 2);

        static::assertCount(2, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 2, 2);

        static::assertCount(2, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 3, 2);

        static::assertCount(1, $audits, 'result count is ok.');

        $this->expectException(\InvalidArgumentException::class);
        $reader->getAudits(Post::class, null, 1, 0);
        $reader->getAudits(Post::class, null, 1, -1);
    }

    /**
     * @depends testGetAudits
     */
    public function testGetAuditsHonorsFilter(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        /** @var AuditEntry[] $audits */
        $audits = $reader->filterBy(AuditReader::UPDATE)->getAudits(Author::class, null, 1, 50);

        static::assertCount(1, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->filterBy(AuditReader::INSERT)->getAudits(Author::class, null, 1, 50);

        static::assertCount(3, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->filterBy(AuditReader::REMOVE)->getAudits(Author::class, null, 1, 50);

        static::assertCount(1, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->filterBy(AuditReader::ASSOCIATE)->getAudits(Author::class, null, 1, 50);

        static::assertCount(0, $audits, 'result count is ok.');

        /** @var AuditEntry[] $audits */
        $audits = $reader->filterBy(AuditReader::DISSOCIATE)->getAudits(Author::class, null, 1, 50);

        static::assertCount(0, $audits, 'result count is ok.');
    }

    /**
     * @depends testGetAudits
     */
    public function testGetAudit(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        $audits = $reader->getAudit(Author::class, 1);

        static::assertCount(1, $audits, 'result count is ok.');
    }

    /**
     * @depends testGetAudits
     */
    public function testGetAuditHonorsFilter(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());

        $audits = $reader->filterBy(AuditReader::UPDATE)->getAudit(Author::class, 1);

        static::assertCount(0, $audits, 'result count is ok.');
    }

    public function testGetAuditByTransactionHash(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);

        $post1 = new Post();
        $post1
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTime())
        ;

        $post2 = new Post();
        $post2
            ->setAuthor($author)
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new \DateTime())
        ;

        $em->persist($post1);
        $em->persist($post2);
        $em->flush();

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Post::class);
        $hash = $audits[0]->getTransactionHash();

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Post::class, null, null, null, $hash);

        static::assertCount(2, $audits, 'result count is ok.');
    }

    public function testGetAllAuditsByTransactionHash(): void
    {
        $em = $this->getEntityManager();
        $reader = $this->getReader($this->getAuditConfiguration());

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author);

        $post1 = new Post();
        $post1
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTime())
        ;

        $post2 = new Post();
        $post2
            ->setAuthor($author)
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new \DateTime())
        ;

        $em->persist($post1);
        $em->persist($post2);
        $em->flush();

        /** @var AuditEntry[] $audits */
        $audits = $reader->getAudits(Post::class);
        $hash = $audits[0]->getTransactionHash();

        $em->remove($post2);
        $em->flush();

        $reader = $this->getReader($this->getAuditConfiguration());
        $audits = $reader->getAuditsByTransactionHash($hash);

        static::assertCount(2, $audits, 'AuditReader::getAllAuditsByTransactionHash() is ok.');
        static::assertCount(1, $audits[Author::class], 'AuditReader::getAllAuditsByTransactionHash() is ok.');
        static::assertCount(2, $audits[Post::class], 'AuditReader::getAllAuditsByTransactionHash() is ok.');
    }
}
