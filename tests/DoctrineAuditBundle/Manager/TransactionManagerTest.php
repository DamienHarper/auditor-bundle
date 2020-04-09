<?php

namespace DH\DoctrineAuditBundle\Tests\Manager;

use DateTime;
use DH\DoctrineAuditBundle\Annotation\AnnotationLoader;
use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Manager\TransactionManager;
use DH\DoctrineAuditBundle\Reader\Reader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Tag;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\CoreCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\DieselCase;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use DH\DoctrineAuditBundle\User\User;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @internal
 */
final class TransactionManagerTest extends CoreTest
{
    public function testInsert(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);
        $reader = new Reader($configuration, $em);

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $manager->insert($em, $author, [
            'fullname' => [null, 'John Doe'],
            'email' => [null, 'john.doe@gmail.com'],
        ], 'what-a-nice-transaction-hash');

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
        $manager = new TransactionManager($configuration);
        $reader = new Reader($configuration, $em);

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $manager->update($em, $author, [
            'fullname' => ['John Doe', 'Dark Vador'],
            'email' => ['john.doe@gmail.com', 'dark.vador@gmail.com'],
        ], 'what-a-nice-transaction-hash');

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
        $manager = new TransactionManager($configuration);
        $reader = new Reader($configuration, $em);

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $manager->remove($em, $author, 1, 'what-a-nice-transaction-hash');

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
        $manager = new TransactionManager($configuration);
        $reader = new Reader($configuration, $em);

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

        $manager->associate($em, $author, $post, $mapping, 'what-a-nice-transaction-hash');

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
        $manager = new TransactionManager($configuration);
        $reader = new Reader($configuration, $em);

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

        $manager->dissociate($em, $author, $post, $mapping, 'what-a-nice-transaction-hash');

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
        $manager = new TransactionManager($configuration);
        $reader = new Reader($configuration, $em);

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

        $manager->associate($em, $post, $tag1, $mapping, 'what-a-nice-transaction-hash');
        $manager->associate($em, $post, $tag2, $mapping, 'what-a-nice-transaction-hash');

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
        $manager = new TransactionManager($configuration);
        $reader = new Reader($configuration, $em);

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

        $manager->associate($em, $post, $tag1, $mapping, 'what-a-nice-transaction-hash');
        $manager->associate($em, $post, $tag2, $mapping, 'what-a-nice-transaction-hash');

        $manager->dissociate($em, $post, $tag2, $mapping, 'what-a-nice-transaction-hash');

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

    public function testGetConfiguration(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        self::assertInstanceOf(Configuration::class, $manager->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
    }

    public function testBlame(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        $expected = [
            'user_id' => 1,
            'username' => 'dark.vador',
            'client_ip' => '1.2.3.4',
            'user_fqdn' => User::class,
            'user_firewall' => null,
        ];

        self::assertSame($expected, $manager->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testBlameWhenNoRequest(): void
    {
        $em = $this->getEntityManager();
        $configuration = new Configuration(
            [
                'enabled' => true,
                'enabled_viewer' => true,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [],
            ],
            $this->getAuditConfiguration()->getUserProvider(),
            new RequestStack(),
            new FirewallMap(new ContainerBuilder(), []),
            $em,
            new AnnotationLoader($em),
            new EventDispatcher()
        );
        $manager = new TransactionManager($configuration);

        $expected = [
            'user_id' => 1,
            'username' => 'dark.vador',
            'client_ip' => null,
            'user_fqdn' => User::class,
            'user_firewall' => null,
        ];

        self::assertSame($expected, $manager->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testBlameWhenNoUser(): void
    {
        $em = $this->getEntityManager();
        $configuration = new Configuration(
            [
                'enabled' => true,
                'enabled_viewer' => true,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [],
            ],
            new TokenStorageUserProvider(new Security(new ContainerBuilder())),
            new RequestStack(),
            new FirewallMap(new ContainerBuilder(), []),
            $em,
            new AnnotationLoader($em),
            new EventDispatcher()
        );
        $manager = new TransactionManager($configuration);

        $expected = [
            'user_id' => null,
            'username' => null,
            'client_ip' => null,
            'user_fqdn' => null,
            'user_firewall' => null,
        ];

        self::assertSame($expected, $manager->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testSummarize(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $expected = [
            'label' => Author::class.'#1',
            'class' => Author::class,
            'table' => 'author',
            'id' => 1,
        ];

        self::assertSame($expected, $manager->summarize($em, $author), 'AuditHelper::summarize ok');

        $post = new Post();
        $post
            ->setId(1)
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new DateTime())
        ;

        $expected = [
            'label' => 'First post',
            'class' => Post::class,
            'table' => 'post',
            'id' => 1,
        ];

        self::assertSame($expected, $manager->summarize($em, $post), 'AuditHelper::summarize is ok.');
    }

    public function testId(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        self::assertSame(1, $manager->id($em, $author), 'AuditHelper::id() is ok.');

        $dieselCore = new CoreCase();
        $dieselCore->type = 'type1';
        $dieselCore->status = 'status1';

        $dieselCase = new DieselCase();
        $dieselCase->setName('name1');
        $dieselCase->coreCase = $dieselCore;

        $em->persist($dieselCore);
        $em->persist($dieselCase);
        $em->flush();

        self::assertSame(1, $manager->id($em, $dieselCore));
        self::assertSame(1, $manager->id($em, $dieselCase));

        $dieselCore = new CoreCase();
        $dieselCore->type = 'type2';
        $dieselCore->status = 'status2';

        $dieselCase->coreCase = $dieselCore;

        $em->persist($dieselCore);
        $em->persist($dieselCase);
        $em->flush();

        self::assertSame(2, $manager->id($em, $dieselCore));
        self::assertSame(2, $manager->id($em, $dieselCase));
    }

    public function testDiffInsert(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $changeset = [
            'email' => [
                null,
                'john.doe@gmail.com',
            ],
            'fullname' => [
                null,
                'John Doe',
            ],
        ];

        $expected = [
            'email' => [
                'old' => null,
                'new' => 'john.doe@gmail.com',
            ],
            'fullname' => [
                'old' => null,
                'new' => 'John Doe',
            ],
        ];

        self::assertSame($expected, $manager->diff($em, $author, $changeset), 'AuditHelper::diff() / insert is ok.');
    }

    public function testDiffUpdate(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        $author = new Author();
        $author
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        $changeset = [
            'email' => [
                'john.doe@gmail.com',
                'dark.vador@gmail.com',
            ],
            'fullname' => [
                'John Doe',
                'Dark Vador',
            ],
        ];

        $expected = [
            'email' => [
                'old' => 'john.doe@gmail.com',
                'new' => 'dark.vador@gmail.com',
            ],
            'fullname' => [
                'old' => 'John Doe',
                'new' => 'Dark Vador',
            ],
        ];

        self::assertSame($expected, $manager->diff($em, $author, $changeset), 'AuditHelper::diff() / update is ok.');
    }

    public function testDiffHonorsGloballyIgnoredColumns(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $configuration = new Configuration(
            [
                'enabled' => true,
                'enabled_viewer' => true,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [
                    'created_at',
                    'updated_at',
                ],
                'entities' => [
                    Post::class => null,
                ],
            ],
            $configuration->getUserProvider(),
            $configuration->getRequestStack(),
            new FirewallMap(new ContainerBuilder(), []),
            $em,
            new AnnotationLoader($em),
            new EventDispatcher()
        );
        $manager = new TransactionManager($configuration);

        $now = new DateTime('now');
        $post = new Post();
        $post
            ->setTitle('First post')
            ->setBody('What a nice first post!')
            ->setCreatedAt($now)
        ;

        $changeset = [
            'body' => [
                null,
                'What a nice first post!',
            ],
            'created_at' => [
                null,
                $now,
            ],
            'title' => [
                null,
                'First post',
            ],
        ];

        $expected = [
            'body' => [
                'old' => null,
                'new' => 'What a nice first post!',
            ],
            'title' => [
                'old' => null,
                'new' => 'First post',
            ],
        ];

        self::assertSame($expected, $manager->diff($em, $post, $changeset), 'AuditHelper::diff() honors globally ignored columns.');
    }

    public function testDiffHonorsLocallyIgnoredColumns(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $configuration = new Configuration(
            [
                'enabled' => true,
                'enabled_viewer' => true,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [
                    Post::class => [
                        'ignored_columns' => [
                            'created_at',
                            'updated_at',
                        ],
                    ],
                ],
            ],
            $configuration->getUserProvider(),
            $configuration->getRequestStack(),
            new FirewallMap(new ContainerBuilder(), []),
            $em,
            new AnnotationLoader($em),
            new EventDispatcher()
        );
        $manager = new TransactionManager($configuration);

        $now = new DateTime('now');
        $post = new Post();
        $post
            ->setTitle('First post')
            ->setBody('What a nice first post!')
            ->setCreatedAt($now)
        ;

        $changeset = [
            'body' => [
                null,
                'What a nice first post!',
            ],
            'created_at' => [
                null,
                $now,
            ],
            'title' => [
                null,
                'First post',
            ],
        ];

        $expected = [
            'body' => [
                'old' => null,
                'new' => 'What a nice first post!',
            ],
            'title' => [
                'old' => null,
                'new' => 'First post',
            ],
        ];

        self::assertSame($expected, $manager->diff($em, $post, $changeset), 'AuditHelper::diff() honors locally ignored columns.');
    }

    public function testDiffIgnoresUnchangedValues(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        $now = new DateTime('now');
        $post = new Post();
        $post
            ->setTitle('First post')
            ->setBody('What a nice first post!')
            ->setCreatedAt($now)
        ;

        $em->persist($post);
        $em->flush();

        $post
            ->setTitle('First post ever!')
            ->setCreatedAt($now)
        ;

        $changeset = [
            'created_at' => [
                $now,
                $now,
            ],
            'title' => [
                'First post',
                'First post ever!',
            ],
        ];

        $expected = [
            'title' => [
                'old' => 'First post',
                'new' => 'First post ever!',
            ],
        ];

        self::assertSame($expected, $manager->diff($em, $post, $changeset), 'AuditHelper::diff() ignores unchanged values.');
    }

    protected function setupEntities(): void
    {
    }
}
