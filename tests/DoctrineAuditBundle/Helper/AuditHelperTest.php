<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post;

/**
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
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

        $this->assertSame($expected, $helper->summarize($em, $author),'AuditHelper::summarize ok');

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

        $this->assertSame($expected, $helper->summarize($em, $post),'AuditHelper::summarize ok');
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
