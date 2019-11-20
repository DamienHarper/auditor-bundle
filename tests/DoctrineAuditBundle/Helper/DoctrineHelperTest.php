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
    public function testGenerateProxyClassName(): void
    {
        self::assertSame('DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\__CG__\Post', DoctrineHelper::generateProxyClassName('Post', mb_substr(Post::class, 0, mb_strrpos(Post::class, '\\'))));
    }

    /**
     * @depends testGenerateProxyClassName
     */
    public function testGetRealName(): void
    {
        self::assertNotSame('Post', DoctrineHelper::getRealClass(Post::class));
        self::assertSame(Post::class, DoctrineHelper::getRealClass(Post::class));
        self::assertSame(Post::class, DoctrineHelper::getRealClass(DoctrineHelper::generateProxyClassName(Post::class, mb_substr(Post::class, 0, mb_strrpos(Post::class, '\\')))));
    }
}
