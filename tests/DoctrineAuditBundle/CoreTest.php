<?php

namespace DH\DoctrineAuditBundle\Tests;

use DateTime;
use DH\DoctrineAuditBundle\Annotation\AnnotationLoader;
use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\DummyEntity;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Tag;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\User;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Security;

abstract class CoreTest extends BaseTest
{
    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    protected function setUp(): void
    {
        $this->getEntityManager();
        $this->getSchemaTool();

        $configuration = $this->getAuditConfiguration();
        $configuration->setEntities([
            Author::class => ['enabled' => true],
            Post::class => ['enabled' => true],
            Comment::class => ['enabled' => true],
            Tag::class => ['enabled' => true],
            DummyEntity::class => ['enabled' => true],
        ]);

        $this->setUpEntitySchema();
        $this->setUpAuditSchema();
        $this->setupEntities();
    }

    /**
     * ++Author 1
     *   +Post 1
     *      +Comment 1
     *   +Post 2
     * +Author 2
     *   +Post 3
     *      +Comment 2
     *      +Comment 3
     * +-Author 3
     *   +-Post 4
     * +Tag 1
     * +Tag 2
     * +Tag 3
     * +Tag 4
     * +Tag 5
     * +PostTag 1.1
     * +PostTag 1.2
     * +PostTag 3.1
     * +PostTag 3.3
     * +PostTag 3.5
     * +-PostTag 4.4
     * +-PostTag 4.5.
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    protected function setupEntities(): void
    {
        $em = $this->getEntityManager();

        $author1 = new Author();
        $author1
            ->setFullname('John')
            ->setEmail('john.doe@gmail.com')
        ;
        $em->persist($author1);

        $post1 = new Post();
        $post1
            ->setAuthor($author1)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post1);

        $comment1 = new Comment();
        $comment1
            ->setPost($post1)
            ->setBody('First comment about post #1')
            ->setAuthor('Dark Vador')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($comment1);

        $post2 = new Post();
        $post2
            ->setAuthor($author1)
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post2);

        $author2 = new Author();
        $author2
            ->setFullname('Chuck Norris')
            ->setEmail('chuck.norris@gmail.com')
        ;
        $em->persist($author2);

        $post3 = new Post();
        $post3
            ->setAuthor($author2)
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post3);

        $comment2 = new Comment();
        $comment2
            ->setPost($post3)
            ->setBody('First comment about post #3')
            ->setAuthor('Yoshi')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($comment2);

        $comment3 = new Comment();
        $comment3
            ->setPost($post3)
            ->setBody('Second comment about post #3')
            ->setAuthor('Mario')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($comment3);

        $em->flush();

        $author1->setFullname('John Doe');
        $em->persist($author1);

        $author3 = new Author();
        $author3
            ->setFullname('Luke Slywalker')
            ->setEmail('luck.skywalker@gmail.com')
        ;
        $em->persist($author3);

        $post4 = new Post();
        $post4
            ->setAuthor($author3)
            ->setTitle('First post')
            ->setBody('Here is the body')
            ->setCreatedAt(new DateTime())
        ;
        $em->persist($post4);

        $tag1 = new Tag();
        $tag1->setTitle('techno');
        $em->persist($tag1);

        $tag2 = new Tag();
        $tag2->setTitle('house');
        $em->persist($tag2);

        $tag3 = new Tag();
        $tag3->setTitle('hardcore');
        $em->persist($tag3);

        $tag4 = new Tag();
        $tag4->setTitle('jungle');
        $em->persist($tag4);

        $tag5 = new Tag();
        $tag5->setTitle('gabber');
        $em->persist($tag5);

        $em->flush();

        $post1
            ->addTag($tag1)
            ->addTag($tag2)
        ;
        $post3
            ->addTag($tag1)
            ->addTag($tag3)
            ->addTag($tag5)
        ;
        $post4
            ->addTag($tag2)
            ->addTag($tag4)
            ->addTag($tag5)
        ;

        $em->flush();

        $post4
            ->removeTag($tag4)
            ->removeTag($tag5)
        ;
        $em->flush();

        $author3->removePost($post4);
        $em->flush();

        $em->remove($author3);
        $em->flush();
    }

    protected function createAuditConfiguration(array $options = [], ?EntityManager $entityManager = null): AuditConfiguration
    {
        $container = new ContainerBuilder();
        $security = new Security($container);
        $tokenStorage = new TokenStorage();

        $user = new User(1, 'dark.vador');
        $user->setRoles(['ROLE_ADMIN']);
        $tokenStorage->setToken(new UsernamePasswordToken($user, '12345', 'provider', $user->getRoles()));

        $authorizationChecker = $this->getMockBuilder(AuthorizationCheckerInterface::class)->getMock();
        $authorizationChecker
            ->expects(static::any())
            ->method('isGranted')
            ->with('ROLE_PREVIOUS_ADMIN')
            ->willReturn(true)
        ;

        $container->set('security.token_storage', $tokenStorage);
        $container->set('security.authorization_checker', $authorizationChecker);

        $requestStack = new RequestStack();
        $requestStack->push(new Request([], [], [], [], [], ['REMOTE_ADDR' => '1.2.3.4']));

        $em = $entityManager ?? $this->getEntityManager();

        return new AuditConfiguration(
            array_merge([
                'enabled' => true,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [],
            ], $options),
            new TokenStorageUserProvider($security),
            $requestStack,
            new FirewallMap($container, []),
            $em,
            new AnnotationLoader($em),
            new EventDispatcher()
        );
    }
}
