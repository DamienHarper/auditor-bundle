<?php

namespace DH\DoctrineAuditBundle\Tests\DependencyInjection;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Command\CleanAuditLogsCommand;
use DH\DoctrineAuditBundle\DependencyInjection\DHDoctrineAuditExtension;
use DH\DoctrineAuditBundle\Event\AuditSubscriber;
use DH\DoctrineAuditBundle\Event\DoctrineSubscriber;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use DH\DoctrineAuditBundle\Twig\Extension\TwigExtension;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;

/**
 * @internal
 */
final class DHDoctrineAuditExtensionTest extends AbstractExtensionTestCase
{
    public function testItRegistersDefaultServices(): void
    {
        $this->load([]);

        $this->assertContainerBuilderHasService('dh_doctrine_audit.user_provider', TokenStorageUserProvider::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.user_provider', 0, 'security.helper');

        $this->assertContainerBuilderHasService('dh_doctrine_audit.configuration', AuditConfiguration::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.configuration', 1, 'dh_doctrine_audit.user_provider');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.configuration', 2, 'request_stack');

        $this->assertContainerBuilderHasService('dh_doctrine_audit.reader', AuditReader::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.reader', 0, 'dh_doctrine_audit.configuration');
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.reader', 1, 'doctrine.orm.default_entity_manager');

        $this->assertContainerBuilderHasService('dh_doctrine_audit.helper', AuditHelper::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.helper', 0, 'dh_doctrine_audit.configuration');

        $this->assertContainerBuilderHasService('dh_doctrine_audit.event_subscriber.audit', AuditSubscriber::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.event_subscriber.audit', 0, 'dh_doctrine_audit.manager');
        $this->assertContainerBuilderHasServiceDefinitionWithTag('dh_doctrine_audit.event_subscriber.audit', 'kernel.event_subscriber');

        $this->assertContainerBuilderHasService('dh_doctrine_audit.event_subscriber.doctrine', DoctrineSubscriber::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.event_subscriber.doctrine', 0, 'dh_doctrine_audit.manager');
        $this->assertContainerBuilderHasServiceDefinitionWithTag('dh_doctrine_audit.event_subscriber.doctrine', 'doctrine.event_subscriber', ['connection' => 'default', 'priority' => 1000000]);

        $this->assertContainerBuilderHasService('dh_doctrine_audit.twig_extension', TwigExtension::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('dh_doctrine_audit.twig_extension', 0, 'doctrine');
        $this->assertContainerBuilderHasServiceDefinitionWithTag('dh_doctrine_audit.twig_extension', 'twig.extension');

        $this->assertContainerBuilderHasService('dh_doctrine_audit.command.clean', CleanAuditLogsCommand::class);
        $this->assertContainerBuilderHasServiceDefinitionWithTag('dh_doctrine_audit.command.clean', 'console.command', ['command' => 'audit:clean']);
    }

    public function testItAliasesDefaultServices(): void
    {
        $this->load([]);

        $this->assertContainerBuilderHasAlias(AuditConfiguration::class, 'dh_doctrine_audit.configuration');
        $this->assertContainerBuilderHasAlias(AuditReader::class, 'dh_doctrine_audit.reader');
        $this->assertContainerBuilderHasAlias(CleanAuditLogsCommand::class, 'dh_doctrine_audit.command.clean');
    }

    public function testItSetsDefaultParameters(): void
    {
        $this->load([]);

        $this->assertContainerBuilderHasParameter(
            'dh_doctrine_audit.configuration',
            [
                'enabled' => true,
                'storage_entity_manager' => null,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'ignored_columns' => [],
                'timezone' => 'UTC',
                'entities' => [],
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getContainerExtensions(): array
    {
        return [
            new DHDoctrineAuditExtension(),
        ];
    }
}
