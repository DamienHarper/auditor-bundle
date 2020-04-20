<?php

declare(strict_types=1);

namespace DH\DoctrineAuditBundle\Tests\Fixtures\IssueX;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DataFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $post = $this->getReference('post_1');

        $comment1 = new Comment();
        $comment1->setId(1);
        $comment1->setCreatedAt(new \DateTime());
        $comment1->setBody('Comment One');
        $comment1->setPost($post);
        $comment1->setAuthor('John Doe');

        $manager->persist($comment1);

        $comment2 = new Comment();
        $comment2->setId(2);
        $comment2->setCreatedAt(new \DateTime());
        $comment2->setBody('Comment Two');
        $comment2->setPost($post);
        $comment2->setAuthor('Charlie Brown');

        $manager->persist($comment2);

        $manager->flush();

        $this->addReference('comment_1', $comment1);
        $this->addReference('comment_2', $comment2);
    }

    public function getDependencies()
    {
        return [DependentDataFixture::class];
    }
}
