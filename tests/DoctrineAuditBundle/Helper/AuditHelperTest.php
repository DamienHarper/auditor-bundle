<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;

/**
 * @internal
 */
final class AuditHelperTest extends CoreTest
{
    public function testParamToNamespace(): void
    {
        self::assertSame(Author::class, AuditHelper::paramToNamespace('DH-DoctrineAuditBundle-Tests-Fixtures-Core-Standard-Author'), 'AuditHelper::paramToNamespace() is ok.');
    }

    public function testNamespaceToParam(): void
    {
        self::assertSame('DH-DoctrineAuditBundle-Tests-Fixtures-Core-Standard-Author', AuditHelper::namespaceToParam(Author::class), 'AuditHelper::namespaceToParam() is ok.');
    }

    public function testGetConfiguration(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

        self::assertInstanceOf(Configuration::class, $helper->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
    }

    protected function setupEntities(): void
    {
    }
}
