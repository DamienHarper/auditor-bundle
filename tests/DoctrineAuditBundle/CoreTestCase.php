<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Comment;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Tag;

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
            Tag::class => ['default' => true],
        ]);

        $this->setUpEntitySchema();
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
            ->setCreatedAt(new \DateTime())
        ;
        $em->persist($post4);


        $tag1 = new Tag();
        $tag1->setTitle('techno');
        $em->persist($tag1);

        $tag2 = new Tag();
        $tag2->setTitle('Second comment about post #3');
        $em->persist($tag2);

        $tag3 = new Tag();
        $tag3->setTitle('Second comment about post #3');
        $em->persist($tag3);

        $tag4 = new Tag();
        $tag4->setTitle('Second comment about post #3');
        $em->persist($tag4);

        $tag5 = new Tag();
        $tag5->setTitle('Second comment about post #3');
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
            ->addTag($tag4)
        ;

        $em->flush();


        $post4->removeTag($tag4);
        $em->flush();

        $author3->removePost($post4);
        $em->flush();

        $em->remove($author3);
        $em->flush();
    }
}
