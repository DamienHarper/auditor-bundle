<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\Helper\UrlHelper;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;

/**
 * @internal
 */
final class UrlHelperTest extends CoreTest
{
    public function testParamToNamespace(): void
    {
        self::assertSame(Author::class, UrlHelper::paramToNamespace('DH-DoctrineAuditBundle-Tests-Fixtures-Core-Standard-Author'), 'AuditHelper::paramToNamespace() is ok.');
    }

    public function testNamespaceToParam(): void
    {
        self::assertSame('DH-DoctrineAuditBundle-Tests-Fixtures-Core-Standard-Author', UrlHelper::namespaceToParam(Author::class), 'AuditHelper::namespaceToParam() is ok.');
    }

    protected function setupEntities(): void
    {
    }
}
