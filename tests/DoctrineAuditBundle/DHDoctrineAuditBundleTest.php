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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @internal
 */
final class DHDoctrineAuditBundleTest extends TestCase
{
    public function testDefaultBuild(): void
    {
        $container = new ContainerBuilder();

        $extension = new DHDoctrineAuditExtension();
        $extension->load([], $container);

        $connection = new Connection(
            [],
            $this->createMock(Driver::class)
        );

        $em = EntityManager::create(
            $connection,
            Setup::createAnnotationMetadataConfiguration([__DIR__.'/Fixtures'], true)
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

        $dispatcher = new EventDispatcher();
        $container->set('event_dispatcher', $dispatcher);

        $bundle = new DHDoctrineAuditBundle();
        $bundle->build($container);

        $container->compile();

        $auditReader = $container->get('dh_doctrine_audit.reader');
        self::assertInstanceOf(AuditReader::class, $auditReader);
    }

    public function testStorageCompilerPass(): void
    {
        $container = new ContainerBuilder();

        $extension = new DHDoctrineAuditExtension();
        $extension->load([
            'dh_doctrine_audit' => [
                'storage_entity_manager' => 'doctrine.orm.secondary_entity_manager',
            ],
        ], $container);

        // main connection and entity manager
        $connection = new Connection([], $this->createMock(Driver::class));
        $em1 = EntityManager::create($connection, Setup::createAnnotationMetadataConfiguration([__DIR__.'/Fixtures'], true));

        $container->set('entity_manager', $em1);
        $container->setAlias('doctrine.orm.default_entity_manager', 'entity_manager');

        // secondary connection and entity manager
        $connection2 = new Connection([], $this->createMock(Driver::class));
        $em2 = EntityManager::create($connection2, Setup::createAnnotationMetadataConfiguration([__DIR__.'/Fixtures'], true));

        $container->set('entity_manager2', $em2);
        $container->setAlias('doctrine.orm.secondary_entity_manager', 'entity_manager2');

        $registry = new Registry(
            $container,
            [],
            [
                'default' => 'entity_manager',
                'secondary' => 'entity_manager2',
            ],
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

        $dispatcher = new EventDispatcher();
        $container->set('event_dispatcher', $dispatcher);

        $bundle = new DHDoctrineAuditBundle();
        $bundle->build($container);

        $container->compile();

        $default_em = $container->get('entity_manager');
        $custom_em = $container->get('dh_doctrine_audit.reader')->getConfiguration()->getEntityManager();

        self::assertNotSame($custom_em, $default_em, 'AuditConfiguration entity manager is not the default one');
    }
}
