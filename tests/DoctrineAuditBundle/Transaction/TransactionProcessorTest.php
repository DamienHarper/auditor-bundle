<?php

namespace DH\DoctrineAuditBundle\Tests\Transaction;

use DateTime;
use DH\DoctrineAuditBundle\Reader\Reader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Tag;
use DH\DoctrineAuditBundle\Tests\ReflectionTrait;
use DH\DoctrineAuditBundle\Transaction\TransactionProcessor;

/**
 * @internal
 */
final class TransactionProcessorTest extends CoreTest
{
    use ReflectionTrait;

    public function testInsert(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $processor = new TransactionProcessor($configuration);
        $reader = new Reader($configuration, $em);

        $method = $this->reflectMethod(TransactionProcessor::class, 'insert');

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $method->invokeArgs($processor, [
            $em,
            $author,
            [
                'fullname' => [null, 'John Doe'],
                'email' => [null, 'john.doe@gmail.com'],
            ],
            'what-a-nice-transaction-hash',
        ]);

        $audits = $reader->getAudits(Author::class);
        self::assertCount(1, $audits, 'Manager::insert() creates an audit entry.');

        $entry = array_shift($audits);
        self::assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::INSERT, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'email' => [
                'old' => null,
                'new' => 'john.doe@gmail.com',
            ],
            'fullname' => [
                'old' => null,
                'new' => 'John Doe',
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    public function testUpdate(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $processor = new TransactionProcessor($configuration);
        $reader = new Reader($configuration, $em);

        $method = $this->reflectMethod(TransactionProcessor::class, 'update');

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $method->invokeArgs($processor, [
            $em,
            $author, [
                'fullname' => ['John Doe', 'Dark Vador'],
                'email' => ['john.doe@gmail.com', 'dark.vador@gmail.com'],
            ],
            'what-a-nice-transaction-hash',
        ]);

        $audits = $reader->getAudits(Author::class);
        self::assertCount(1, $audits, 'Manager::update() creates an audit entry.');

        $entry = array_shift($audits);
        self::assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::UPDATE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'email' => [
                'old' => 'john.doe@gmail.com',
                'new' => 'dark.vador@gmail.com',
            ],
            'fullname' => [
                'old' => 'John Doe',
                'new' => 'Dark Vador',
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    public function testRemove(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $processor = new TransactionProcessor($configuration);
        $reader = new Reader($configuration, $em);

        $method = $this->reflectMethod(TransactionProcessor::class, 'remove');

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $method->invokeArgs($processor, [$em, $author, 1, 'what-a-nice-transaction-hash']);

        $audits = $reader->getAudits(Author::class);
        self::assertCount(1, $audits, 'Manager::remove() creates an audit entry.');

        $entry = array_shift($audits);
        self::assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::REMOVE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'label' => Author::class.'#1',
            'class' => Author::class,
            'table' => $em->getClassMetadata(Author::class)->getTableName(),
            'id' => 1,
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    public function testAssociateOneToMany(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $processor = new TransactionProcessor($configuration);
        $reader = new Reader($configuration, $em);

        $method = $this->reflectMethod(TransactionProcessor::class, 'associate');

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $post = new Post();
        $post
            ->setId(1)
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new DateTime())
        ;

        $mapping = [
            'fieldName' => 'posts',
            'mappedBy' => 'author',
            'targetEntity' => Post::class,
            'cascade' => [
                0 => 'persist',
                1 => 'remove',
            ],
            'orphanRemoval' => false,
            'fetch' => 2,
            'type' => 4,
            'inversedBy' => null,
            'isOwningSide' => false,
            'sourceEntity' => Author::class,
            'isCascadeRemove' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => false,
            'isCascadeMerge' => false,
            'isCascadeDetach' => false,
        ];

        $method->invokeArgs($processor, [$em, $author, $post, $mapping, 'what-a-nice-transaction-hash']);

        $audits = $reader->getAudits(Author::class);
        self::assertCount(1, $audits, 'Manager::associate() creates an audit entry.');

        $entry = array_shift($audits);
        self::assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::ASSOCIATE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'source' => [
                'label' => Author::class.'#1',
                'class' => Author::class,
                'table' => $em->getClassMetadata(Author::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => (string) $post,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    public function testDissociateOneToMany(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $processor = new TransactionProcessor($configuration);
        $reader = new Reader($configuration, $em);

        $method = $this->reflectMethod(TransactionProcessor::class, 'dissociate');

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $post = new Post();
        $post
            ->setId(1)
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new DateTime())
        ;

        $mapping = [
            'fieldName' => 'posts',
            'mappedBy' => 'author',
            'targetEntity' => Post::class,
            'cascade' => ['persist', 'remove'],
            'orphanRemoval' => false,
            'fetch' => 2,
            'type' => 4,
            'inversedBy' => null,
            'isOwningSide' => false,
            'sourceEntity' => Author::class,
            'isCascadeRemove' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => false,
            'isCascadeMerge' => false,
            'isCascadeDetach' => false,
        ];

        $method->invokeArgs($processor, [$em, $author, $post, $mapping, 'what-a-nice-transaction-hash']);

        $audits = $reader->getAudits(Author::class);
        self::assertCount(1, $audits, 'Manager::dissociate() creates an audit entry.');

        $entry = array_shift($audits);
        self::assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::DISSOCIATE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'source' => [
                'label' => Author::class.'#1',
                'class' => Author::class,
                'table' => $em->getClassMetadata(Author::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => (string) $post,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    public function testAssociateManyToMany(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $processor = new TransactionProcessor($configuration);
        $reader = new Reader($configuration, $em);

        $method = $this->reflectMethod(TransactionProcessor::class, 'associate');

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $post = new Post();
        $post
            ->setId(1)
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new DateTime())
        ;

        $tag1 = new Tag();
        $tag1
            ->setId(1)
            ->setTitle('techno')
        ;

        $tag2 = new Tag();
        $tag2
            ->setId(2)
            ->setTitle('house')
        ;

        $post->addTag($tag1);
        $post->addTag($tag2);

        $mapping = [
            'fieldName' => 'tags',
            'joinTable' => [
                'name' => 'post__tag',
                'schema' => null,
                'joinColumns' => [
                    [
                        'name' => 'post_id',
                        'unique' => false,
                        'nullable' => false,
                        'onDelete' => null,
                        'columnDefinition' => null,
                        'referencedColumnName' => 'id',
                    ],
                ],
                'inverseJoinColumns' => [
                    [
                        'name' => 'tag_id',
                        'unique' => false,
                        'nullable' => false,
                        'onDelete' => null,
                        'columnDefinition' => null,
                        'referencedColumnName' => 'id',
                    ],
                ],
            ],
            'targetEntity' => Tag::class,
            'mappedBy' => null,
            'inversedBy' => 'posts',
            'cascade' => ['persist', 'remove'],
            'orphanRemoval' => false,
            'fetch' => 2,
            'type' => 8,
            'isOwningSide' => true,
            'sourceEntity' => Post::class,
            'isCascadeRemove' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => false,
            'isCascadeMerge' => false,
            'isCascadeDetach' => false,
            'joinTableColumns' => ['post_id', 'tag_id'],
            'relationToSourceKeyColumns' => [
                'post_id' => 'id',
            ],
            'relationToTargetKeyColumns' => [
                'tag_id' => 'id',
            ],
        ];

        $method->invokeArgs($processor, [$em, $post, $tag1, $mapping, 'what-a-nice-transaction-hash']);
        $method->invokeArgs($processor, [$em, $post, $tag2, $mapping, 'what-a-nice-transaction-hash']);

        $audits = $reader->getAudits(Post::class);
        self::assertCount(2, $audits, 'Manager::associate() creates an audit entry per association.');

        $entry = array_shift($audits);
        self::assertSame(2, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::ASSOCIATE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'source' => [
                'label' => (string) $post,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => Tag::class.'#2',
                'class' => Tag::class,
                'table' => $em->getClassMetadata(Tag::class)->getTableName(),
                'id' => 2,
            ],
            'table' => 'post__tag',
        ], $entry->getDiffs(), 'audit entry diffs is ok.');

        $entry = array_shift($audits);
        self::assertSame(1, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::ASSOCIATE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'source' => [
                'label' => (string) $post,
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => Tag::class.'#1',
                'class' => Tag::class,
                'table' => $em->getClassMetadata(Tag::class)->getTableName(),
                'id' => 1,
            ],
            'table' => 'post__tag',
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    public function testDissociateManyToMany(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $processor = new TransactionProcessor($configuration);
        $reader = new Reader($configuration, $em);

        $associateMethod = $this->reflectMethod(TransactionProcessor::class, 'associate');
        $dissociateMethod = $this->reflectMethod(TransactionProcessor::class, 'dissociate');

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $post = new Post();
        $post
            ->setId(1)
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new DateTime())
        ;

        $tag1 = new Tag();
        $tag1
            ->setId(1)
            ->setTitle('techno')
        ;

        $tag2 = new Tag();
        $tag2
            ->setId(2)
            ->setTitle('house')
        ;

        $post->addTag($tag1);
        $post->addTag($tag2);

        $mapping = [
            'fieldName' => 'tags',
            'joinTable' => [
                'name' => 'post__tag',
                'schema' => null,
                'joinColumns' => [
                    [
                        'name' => 'post_id',
                        'unique' => false,
                        'nullable' => false,
                        'onDelete' => null,
                        'columnDefinition' => null,
                        'referencedColumnName' => 'id',
                    ],
                ],
                'inverseJoinColumns' => [
                    [
                        'name' => 'tag_id',
                        'unique' => false,
                        'nullable' => false,
                        'onDelete' => null,
                        'columnDefinition' => null,
                        'referencedColumnName' => 'id',
                    ],
                ],
            ],
            'targetEntity' => Tag::class,
            'mappedBy' => null,
            'inversedBy' => 'posts',
            'cascade' => ['persist', 'remove'],
            'orphanRemoval' => false,
            'fetch' => 2,
            'type' => 8,
            'isOwningSide' => true,
            'sourceEntity' => Post::class,
            'isCascadeRemove' => true,
            'isCascadePersist' => true,
            'isCascadeRefresh' => false,
            'isCascadeMerge' => false,
            'isCascadeDetach' => false,
            'joinTableColumns' => ['post_id', 'tag_id'],
            'relationToSourceKeyColumns' => [
                'post_id' => 'id',
            ],
            'relationToTargetKeyColumns' => [
                'tag_id' => 'id',
            ],
        ];

        $associateMethod->invokeArgs($processor, [$em, $post, $tag1, $mapping, 'what-a-nice-transaction-hash']);
        $associateMethod->invokeArgs($processor, [$em, $post, $tag2, $mapping, 'what-a-nice-transaction-hash']);

        $dissociateMethod->invokeArgs($processor, [$em, $post, $tag2, $mapping, 'what-a-nice-transaction-hash']);

        $audits = $reader->getAudits(Post::class);
        self::assertCount(3, $audits, 'Manager::dissociate() creates an audit entry.');

        $entry = array_shift($audits);
        self::assertSame(3, $entry->getId(), 'audit entry ID is ok.');
        self::assertSame(Reader::DISSOCIATE, $entry->getType(), 'audit entry type is ok.');
        self::assertSame('1', $entry->getUserId(), 'audit entry blame_id is ok.');
        self::assertSame('dark.vador', $entry->getUsername(), 'audit entry blame_user is ok.');
        self::assertSame('1.2.3.4', $entry->getIp(), 'audit entry IP is ok.');
        self::assertEquals([
            'source' => [
                'label' => 'First post',
                'class' => Post::class,
                'table' => $em->getClassMetadata(Post::class)->getTableName(),
                'id' => 1,
            ],
            'target' => [
                'label' => Tag::class.'#2',
                'class' => Tag::class,
                'table' => $em->getClassMetadata(Tag::class)->getTableName(),
                'id' => 2,
            ],
            'table' => 'post__tag',
        ], $entry->getDiffs(), 'audit entry diffs is ok.');
    }

    protected function setupEntities(): void
    {
    }
}
