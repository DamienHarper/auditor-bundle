<?php

namespace DH\DoctrineAuditBundle\Tests\Manager;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Manager\AuditManager;
use DH\DoctrineAuditBundle\Manager\AuditTransaction;
use DH\DoctrineAuditBundle\Tests\BaseTest;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Helper\UpdateHelper
 * @covers \DH\DoctrineAuditBundle\Manager\AuditManager
 * @covers \DH\DoctrineAuditBundle\Manager\AuditTransaction
 * @covers \DH\DoctrineAuditBundle\Reader\AuditEntry
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 *
 * @internal
 */
final class AuditTransactionTest extends BaseTest
{
    public function testGetTransactionHash(): void
    {
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);
        $transaction = new AuditTransaction($helper);

        $transaction_hash = $transaction->getTransactionHash();
        static::assertNotNull($transaction_hash, 'transaction_hash is not null');
        static::assertIsString($transaction_hash, 'transaction_hash is a string');
        static::assertSame(40, mb_strlen($transaction_hash), 'transaction_hash is a string of 40 characters');
    }

//    public function testTransactionHashAfterReset(): void
//    {
//        $configuration = $this->getAuditConfiguration();
//        $helper = new AuditHelper($configuration);
//        $manager = new AuditManager($configuration, $helper);
//
//        $before = $manager->getTransactionHash();
//        $manager->reset();
//        $after = $manager->getTransactionHash();
//
//        static::assertNotSame($after, $before, 'transaction_hash is reset by AuditManager::reset()');
//    }

    protected function getAuditConfiguration(array $options = [], ?EntityManager $entityManager = null): AuditConfiguration
    {
        $container = new ContainerBuilder();

        return new AuditConfiguration(
            array_merge([
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [],
                'enabled' => true,
            ], $options),
            new TokenStorageUserProvider(new Security($container)),
            new RequestStack(),
            new FirewallMap($container, []),
            $entityManager ?? $this->getEntityManager()
        );
    }

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }
}
