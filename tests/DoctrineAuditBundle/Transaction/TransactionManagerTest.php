<?php

namespace DH\DoctrineAuditBundle\Tests\Transaction;

use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Transaction\TransactionManager;

/**
 * @internal
 */
final class TransactionManagerTest extends CoreTest
{
    public function testGetConfiguration(): void
    {
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        self::assertInstanceOf(Configuration::class, $manager->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
    }

    protected function setupEntities(): void
    {
    }
}
