<?php

namespace DH\AuditorBundle\Tests\Helper;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\AuditorBundle\Helper\UrlHelper;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class UrlHelperTest extends TestCase
{
    public function testParamToNamespace(): void
    {
        self::assertSame(Author::class, UrlHelper::paramToNamespace('DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author'), 'AuditHelper::paramToNamespace() is ok.');
    }

    public function testNamespaceToParam(): void
    {
        self::assertSame('DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author', UrlHelper::namespaceToParam(Author::class), 'AuditHelper::namespaceToParam() is ok.');
    }
}
