<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Comment;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post;

abstract class CoreTestCase extends BaseTestCase
{
    /**
     * @var string
     */
    protected $fixturesPath = __DIR__ . '/Fixtures';

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    public function setUp(): void
    {
        $this->getEntityManager();
        $this->getSchemaTool();

        $configuration = $this->getAuditConfiguration();
        $configuration->setEntities([
            Author::class => ['default' => true],
            Post::class => ['default' => true],
            Comment::class => ['default' => true],
        ]);

        $this->setUpEntitySchema();
        $this->setupEntities();
    }

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
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post1);

        $comment1 = new Comment();
        $comment1
            ->setPost($post1)
            ->setBody('First comment about post #1')
            ->setAuthor('Dark Vador')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($comment1);

        $post2 = new Post();
        $post2
            ->setAuthor($author1)
            ->setTitle('Second post')
            ->setBody('Here is another body')
            ->setCreatedAt(new \DateTime())
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
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post3);

        $comment2 = new Comment();
        $comment2
            ->setPost($post3)
            ->setBody('First comment about post #3')
            ->setAuthor('Yoshi')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($comment2);

        $comment3 = new Comment();
        $comment3
            ->setPost($post3)
            ->setBody('Second comment about post #3')
            ->setAuthor('Mario')
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($comment3);

        $em->flush();

        $author1->setFullname('John Doe');
        $em->persist($author1);

        $em->flush();
    }
}
