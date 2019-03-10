<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issues\CoreCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issues\DieselCase;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditManager
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Reader\AuditEntry
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 * @covers \DH\DoctrineAuditBundle\User\User
 */
class AuditHelperTest extends CoreTest
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

        $this->assertSame($expected, $helper->summarize($em, $author), 'AuditHelper::summarize ok');

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

        $this->assertSame($expected, $helper->summarize($em, $post), 'AuditHelper::summarize is ok.');
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

        $this->assertSame(1, $helper->id($em, $author), 'AuditHelper::id() is ok.');

        $dieselCore = new CoreCase();
        $dieselCore->type = 'type1';
        $dieselCore->status = 'status1';

        $dieselCase = new DieselCase();
        $dieselCase->setName('name1');
        $dieselCase->coreCase = $dieselCore;

        $em->persist($dieselCore);
        $em->persist($dieselCase);
        $em->flush();

        $this->assertSame(1, $helper->id($em, $dieselCore));
        $this->assertSame(1, $helper->id($em, $dieselCase));

        $dieselCore = new CoreCase();
        $dieselCore->type = 'type2';
        $dieselCore->status = 'status2';

        $dieselCase->coreCase = $dieselCore;

        $em->persist($dieselCore);
        $em->persist($dieselCase);
        $em->flush();

        $this->assertSame(2, $helper->id($em, $dieselCore));
        $this->assertSame(2, $helper->id($em, $dieselCase));
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
            'fullname' => [
                null,
                'John Doe',
            ],
            'email' => [
                null,
                'john.doe@gmail.com',
            ],
        ];

        $expected = [
            'fullname' => [
                'old' => null,
                'new' => 'John Doe',
            ],
            'email' => [
                'old' => null,
                'new' => 'john.doe@gmail.com',
            ],
        ];

        $this->assertSame($expected, $helper->diff($em, $author, $changeset), 'AuditHelper::diff() / insert is ok.');
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
            'fullname' => [
                'John Doe',
                'Dark Vador',
            ],
            'email' => [
                'john.doe@gmail.com',
                'dark.vador@gmail.com',
            ],
        ];

        $expected = [
            'fullname' => [
                'old' => 'John Doe',
                'new' => 'Dark Vador',
            ],
            'email' => [
                'old' => 'john.doe@gmail.com',
                'new' => 'dark.vador@gmail.com',
            ],
        ];

        $this->assertSame($expected, $helper->diff($em, $author, $changeset), 'AuditHelper::diff() / update is ok.');
    }

    public function testDiffHonorsGloballyIgnoredColumns(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $configuration = new AuditConfiguration(
            [
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'ignored_columns' => [
                    'created_at',
                    'updated_at',
                ],
                'entities' => [
                    Post::class => null,
                ],
            ],
            $configuration->getUserProvider(),
            $configuration->getRequestStack()
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
            'title' => [
                null,
                'First post',
            ],
            'body' => [
                null,
                'What a nice first post!',
            ],
            'created_at' => [
                null,
                $now,
            ],
        ];

        $expected = [
            'title' => [
                'old' => null,
                'new' => 'First post',
            ],
            'body' => [
                'old' => null,
                'new' => 'What a nice first post!',
            ],
        ];

        $this->assertSame($expected, $helper->diff($em, $post, $changeset), 'AuditHelper::diff() honors globally ignored columns.');
    }

    public function testDiffHonorsLocallyIgnoredColumns(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $configuration = new AuditConfiguration(
            [
                'table_prefix' => '',
                'table_suffix' => '_audit',
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
            $configuration->getRequestStack()
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
            'title' => [
                null,
                'First post',
            ],
            'body' => [
                null,
                'What a nice first post!',
            ],
            'created_at' => [
                null,
                $now,
            ],
        ];

        $expected = [
            'title' => [
                'old' => null,
                'new' => 'First post',
            ],
            'body' => [
                'old' => null,
                'new' => 'What a nice first post!',
            ],
        ];

        $this->assertSame($expected, $helper->diff($em, $post, $changeset), 'AuditHelper::diff() honors locally ignored columns.');
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
            'title' => [
                'First post',
                'First post ever!',
            ],
            'created_at' => [
                $now,
                $now,
            ],
        ];

        $expected = [
            'title' => [
                'old' => 'First post',
                'new' => 'First post ever!',
            ],
        ];

        $this->assertSame($expected, $helper->diff($em, $post, $changeset), 'AuditHelper::diff() ignores unchanged values.');
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
        ];

        $this->assertSame($expected, $helper->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testBlameWhenNoRequest(): void
    {
        $em = $this->getEntityManager();
        $configuration = new AuditConfiguration(
            [
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'ignored_columns' => [],
                'entities' => [],
            ],
            $this->getAuditConfiguration()->getUserProvider(),
            new RequestStack()
        );
        $helper = new AuditHelper($configuration);

        $expected = [
            'user_id' => 1,
            'username' => 'dark.vador',
            'client_ip' => null,
        ];

        $this->assertSame($expected, $helper->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testBlameWhenNoUser(): void
    {
        $configuration = new AuditConfiguration(
            [
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'ignored_columns' => [],
                'entities' => [],
            ],
            new TokenStorageUserProvider(new Security(new ContainerBuilder())),
            new RequestStack()
        );
        $helper = new AuditHelper($configuration);

        $expected = [
            'user_id' => null,
            'username' => null,
            'client_ip' => null,
        ];

        $this->assertSame($expected, $helper->blame(), 'AuditHelper::blame() is ok.');
    }

    public function testGetConfiguration(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

        $this->assertInstanceOf(AuditConfiguration::class, $helper->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
    }

    protected function setupEntities(): void
    {
    }
}
