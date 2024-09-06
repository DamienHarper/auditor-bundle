<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Helper;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\AuditorBundle\Helper\UrlHelper;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class UrlHelperTest extends TestCase
{
    public function testParamToNamespace(): void
    {
        $this->assertSame(Author::class, UrlHelper::paramToNamespace('DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author'), 'AuditHelper::paramToNamespace() is ok.');
    }

    public function testNamespaceToParam(): void
    {
        $this->assertSame('DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author', UrlHelper::namespaceToParam(Author::class), 'AuditHelper::namespaceToParam() is ok.');
    }
}
