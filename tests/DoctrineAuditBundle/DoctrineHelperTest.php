<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\DoctrineHelper;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DH\DoctrineAuditBundle\DoctrineHelper
 */
class DoctrineHelperTest extends TestCase
{
    public function testGenerateProxyClassName(): void
    {
        $this->assertSame('DH\DoctrineAuditBundle\Tests\Fixtures\Core\__CG__\Post', DoctrineHelper::generateProxyClassName('Post', substr(Post::class, 0, strrpos(Post::class, '\\'))));
    }

    /**
     * @depends testGenerateProxyClassName
     */
    public function testGetRealName(): void
    {
        $this->assertNotSame('Post', DoctrineHelper::getRealClass(Post::class));
        $this->assertSame(Post::class, DoctrineHelper::getRealClass(Post::class));
        $this->assertSame(Post::class, DoctrineHelper::getRealClass(DoctrineHelper::generateProxyClassName(Post::class, substr(Post::class, 0, strrpos(Post::class, '\\')))));
    }
}
