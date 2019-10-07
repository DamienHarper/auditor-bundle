<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\DependencyInjection\DHDoctrineAuditExtension;
use DH\DoctrineAuditBundle\DHDoctrineAuditBundle;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Setup;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\DependencyInjection\Configuration
 * @covers \DH\DoctrineAuditBundle\DependencyInjection\DHDoctrineAuditExtension
 * @covers \DH\DoctrineAuditBundle\DHDoctrineAuditBundle
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 *
 * @internal
 */
final class DHDoctrineAuditBundleTest extends TestCase
{
    public function testDefaultBuild(): void
    {
        $container = new ContainerBuilder();

        $bundle = new DHDoctrineAuditBundle();
        $bundle->build($container);

        $extension = new DHDoctrineAuditExtension();
        $extension->load([], $container);

        $connection = new Connection(
            [],
            $this->createMock(Driver::class)
        );

        $em = EntityManager::create(
            $connection,
            Setup::createAnnotationMetadataConfiguration([__DIR__.'/Fixtures'])
        );

        $container->set('entity_manager', $em);

        $container->setAlias('doctrine.orm.default_entity_manager', 'entity_manager');

        $registry = new Registry(
            $container,
            [],
            ['default' => 'entity_manager'],
            'default',
            'default'
        );

        $container->set('doctrine', $registry);

        $security = new Security($container);
        $container->set('security.helper', $security);

        $requestStack = new RequestStack();
        $container->set('request_stack', $requestStack);

        $firewallMap = new FirewallMap($container, []);
        $container->set('security.firewall.map', $firewallMap);

        $container->compile();

        $auditReader = $container->get('dh_doctrine_audit.reader');
        static::assertInstanceOf(AuditReader::class, $auditReader);
    }
}
