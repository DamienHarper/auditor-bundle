<?php

declare(strict_types=1);

namespace DH\DoctrineAuditBundle\Tests\Fixtures\IssueX;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class DependentDataFixture extends Fixture
{
    public function load(ObjectManager $manager)
    {
        $post = new Post();
        $post->setId(1);
        $post->setTitle('I\'m a title');
        $post->setBody('I\'m a post\'s body');
        $post->setCreatedAt(new \DateTime());
        $manager->persist($post);
        $manager->flush();

        $this->addReference('post_1', $post);
    }
}
