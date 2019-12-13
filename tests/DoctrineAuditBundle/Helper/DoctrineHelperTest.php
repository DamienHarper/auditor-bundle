<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\Helper\DoctrineHelper;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class DoctrineHelperTest extends TestCase
{
    public function testGetRealName(): void
    {
        self::assertNotSame('Post', DoctrineHelper::getRealClassName(Post::class));
        self::assertNotSame('Comment', DoctrineHelper::getRealClassName(Comment::class));

        self::assertSame(Post::class, DoctrineHelper::getRealClassName(Post::class));
        self::assertSame(Comment::class, DoctrineHelper::getRealClassName(Comment::class));

        self::assertSame('DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post', DoctrineHelper::getRealClassName(Post::class));
        self::assertSame('DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment', DoctrineHelper::getRealClassName(Comment::class));

        $post = new Post();
        self::assertSame(Post::class, DoctrineHelper::getRealClassName($post));

        $comment = new Comment();
        self::assertSame(Comment::class, DoctrineHelper::getRealClassName($comment));

        // __CG__: Doctrine Common Marker for Proxy (ODM < 2.0 and ORM < 3.0)
        self::assertSame(Post::class, DoctrineHelper::getRealClassName('Proxies\__CG__\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post'));
        self::assertSame(Comment::class, DoctrineHelper::getRealClassName('Proxies\__CG__\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment'));

        // __PM__: Ocramius Proxy Manager (ODM >= 2.0)
        self::assertSame(Post::class, DoctrineHelper::getRealClassName('ProxyManagerGeneratedProxy\__PM__\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post\Generated309fa39f737280002e5d4e613b403125'));
        self::assertSame(Post::class, DoctrineHelper::getRealClassName('MongoDBODMProxies\__PM__\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post\Generated309fa39f737280002e5d4e613b403125'));
        self::assertSame(Comment::class, DoctrineHelper::getRealClassName('ProxyManagerGeneratedProxy\__PM__\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment\Generated309fa39f737280002e5d4e613b403125'));
        self::assertSame(Comment::class, DoctrineHelper::getRealClassName('MongoDBODMProxies\__PM__\DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment\Generated309fa39f737280002e5d4e613b403125'));
    }
}
