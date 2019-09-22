<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\CoreCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\DieselCase;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use DH\DoctrineAuditBundle\User\User;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditManager
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLoggerChain
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Helper\UpdateHelper
 * @covers \DH\DoctrineAuditBundle\Reader\AuditEntry
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 * @covers \DH\DoctrineAuditBundle\User\User
 *
 * @internal
 */
final class AuditHelperTest extends CoreTest
{
    public function testSummarize(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

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

        static::assertSame($expected, $helper->summarize($em, $author), 'AuditHelper::summarize ok');

        $post = new Post();
        $post
            ->setId(1)
            ->setAuthor($author)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new \DateTime())
        ;

        $expected = [
            'label' => 'First post',
            'class' => Post::class,
            'table' => 'post',
            'id' => 1,
        ];

        static::assertSame($expected, $helper->summarize($em, $post), 'AuditHelper::summarize is ok.');
    }

    public function testId(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

        $author = new Author();
        $author
            ->setId(1)
            ->setFullname('John Doe')
            ->setEmail('john.doe@gmail.com')
        ;

        static::assertSame(1, $helper->id($em, $author), 'AuditHelper::id() is ok.');

        $dieselCore = new CoreCase();
        $dieselCore->type = 'type1';
        $dieselCore->status = 'status1';

        $dieselCase = new DieselCase();
        $dieselCase->setName('name1');
        $dieselCase->coreCase = $dieselCore;

        $em->persist($dieselCore);
        $em->persist($dieselCase);
        $em->flush();

        static::assertSame(1, $helper->id($em, $dieselCore));
        static::assertSame(1, $helper->id($em, $dieselCase));

        $dieselCore = new CoreCase();
        $dieselCore->type = 'type2';
        $dieselCore->status = 'status2';

        $dieselCase->coreCase = $dieselCore;

        $em->persist($dieselCore);
        $em->persist($dieselCase);
        $em->flush();

        static::assertSame(2, $helper->id($em, $dieselCore));
        static::assertSame(2, $helper->id($em, $dieselCase));
    }

    public function testDiffInsert(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

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

        static::assertSame($expected, $helper->diff($em, $author, $changeset), 'AuditHelper::diff() / insert is ok.');
    }

    public function testDiffUpdate(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

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

        static::assertSame($expected, $helper->diff($em, $author, $changeset), 'AuditHelper::diff() / update is ok.');
    }

    public function testDiffHonorsGloballyIgnoredColumns(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $configuration = new AuditConfiguration(
            [
                'enabled' => true,
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
            $em
        );
        $helper = new AuditHelper($configuration);

        $now = new \DateTime('now');
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

        static::assertSame($expected, $helper->diff($em, $post, $changeset), 'AuditHelper::diff() honors globally ignored columns.');
    }

    public function testDiffHonorsLocallyIgnoredColumns(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $configuration = new AuditConfiguration(
            [
                'enabled' => true,
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
            $em
        );
        $helper = new AuditHelper($configuration);

        $now = new \DateTime('now');
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

        static::assertSame($expected, $helper->diff($em, $post, $changeset), 'AuditHelper::diff() honors locally ignored columns.');
    }

    public function testDiffIgnoresUnchangedValues(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

        $now = new \DateTime('now');
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

        static::assertSame($expected, $helper->diff($em, $post, $changeset), 'AuditHelper::diff() ignores unchanged values.');
    }

    public function testBlame(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

        $expected = [
            'user_id' => 1,
            'username' => 'dark.vador',
            'client_ip' => '1.2.3.4',
            'user_fqdn' => User::class,
            'user_firewall' => null,
        ];

        static::assertSame($expected, $helper->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testBlameWhenNoRequest(): void
    {
        $em = $this->getEntityManager();
        $configuration = new AuditConfiguration(
            [
                'enabled' => true,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [],
            ],
            $this->getAuditConfiguration()->getUserProvider(),
            new RequestStack(),
            new FirewallMap(new ContainerBuilder(), []),
            $em
        );
        $helper = new AuditHelper($configuration);

        $expected = [
            'user_id' => 1,
            'username' => 'dark.vador',
            'client_ip' => null,
            'user_fqdn' => User::class,
            'user_firewall' => null,
        ];

        static::assertSame($expected, $helper->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testBlameWhenNoUser(): void
    {
        $configuration = new AuditConfiguration(
            [
                'enabled' => true,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [],
            ],
            new TokenStorageUserProvider(new Security(new ContainerBuilder())),
            new RequestStack(),
            new FirewallMap(new ContainerBuilder(), []),
            $this->getEntityManager()
        );
        $helper = new AuditHelper($configuration);

        $expected = [
            'user_id' => null,
            'username' => null,
            'client_ip' => null,
            'user_fqdn' => null,
            'user_firewall' => null,
        ];

        static::assertSame($expected, $helper->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testParamToNamespace(): void
    {
        static::assertSame(Author::class, AuditHelper::paramToNamespace('DH-DoctrineAuditBundle-Tests-Fixtures-Core-Author'), 'AuditHelper::paramToNamespace() is ok.');
    }

    public function testNamespaceToParam(): void
    {
        static::assertSame('DH-DoctrineAuditBundle-Tests-Fixtures-Core-Author', AuditHelper::namespaceToParam(Author::class), 'AuditHelper::namespaceToParam() is ok.');
    }

    public function testGetConfiguration(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

        static::assertInstanceOf(AuditConfiguration::class, $helper->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
    }

    protected function setupEntities(): void
    {
    }
}
