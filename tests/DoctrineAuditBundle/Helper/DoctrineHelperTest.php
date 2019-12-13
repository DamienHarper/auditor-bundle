<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\Helper\DoctrineHelper;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DoctrineHelperTest extends TestCase
{
    /**
     * 
     */
    public function testGetRealName(): void
    {
        self::assertNotSame('Post', DoctrineHelper::getRealClassName(Post::class));
        self::assertSame(Post::class, DoctrineHelper::getRealClassName(Post::class));
    }
}
