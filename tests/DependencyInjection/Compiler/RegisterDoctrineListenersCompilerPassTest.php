<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\Auditing\Event\DoctrineSubscriber;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\AuditorBundle\DependencyInjection\Compiler\RegisterDoctrineListenersCompilerPass;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
#[Small]
final class RegisterDoctrineListenersCompilerPassTest extends AbstractCompilerPassTestCase
{
    public function testSubscriberIsDeclaredOnTheConnectionsOfTheAuditingEntityManagers(): void
    {
        $this->setUpDoctrine(['@doctrine.orm.default_entity_manager', 'doctrine.orm.second_entity_manager', 'doctrine.orm.third_entity_manager']);

        $this->compile();

        $subscriber = $this->container->getDefinition(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
        $this->assertSame(DoctrineSubscriber::class, $subscriber->getClass());
        $factory = $subscriber->getFactory();
        $this->assertIsArray($factory);
        $this->assertInstanceOf(Reference::class, $factory[0]);
        $this->assertSame(DoctrineProvider::class, (string) $factory[0]);
        $this->assertSame('getDoctrineSubscriber', $factory[1]);

        $listeners = [];
        foreach ($subscriber->getTag('doctrine.event_listener') as $tag) {
            $listeners[] = $tag['connection'].'.'.$tag['event'];
            // after the other listeners, as when the provider attached it at runtime
            $this->assertSame(RegisterDoctrineListenersCompilerPass::PRIORITY, $tag['priority']);
        }

        // the default and third entity managers share a connection; the "other" connection has no auditing entity manager
        $this->assertSame(['default.onFlush', 'default.postFlush', 'default.postSoftDelete', 'second.onFlush', 'second.postFlush', 'second.postSoftDelete'], $listeners);
    }

    public function testProviderDoesNotAttachTheListenersBeforeRegisteringTheAuditingServices(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);

        $this->compile();

        $this->assertSame(
            ['disableListenerAttachment', 'setLogger', 'registerAuditingService'],
            array_column($this->container->getDefinition(DoctrineProvider::class)->getMethodCalls(), 0),
        );
    }

    public function testEntityManagerAliasIsResolved(): void
    {
        $this->setUpDoctrine(['doctrine.orm.entity_manager']);
        $this->container->setAlias('doctrine.orm.entity_manager', new Alias('doctrine.orm.second_entity_manager'));

        $this->compile();

        $tags = $this->container->getDefinition(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID)->getTag('doctrine.event_listener');
        $this->assertSame(['second'], array_values(array_unique(array_column($tags, 'connection'))));
    }

    public function testConnectionAliasesAreResolved(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->setAlias('database_connection', 'doctrine.dbal.second_connection');
        $this->container->setAlias('app.event_manager', 'doctrine.dbal.second_connection.event_manager');
        $this->container->getDefinition('doctrine.orm.default_entity_manager')->setArguments([new Reference('database_connection'), new Reference('doctrine.orm.default_configuration'), new Reference('app.event_manager')]);

        $this->compile();

        $tags = $this->container->getDefinition(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID)->getTag('doctrine.event_listener');
        $this->assertSame(['second'], array_values(array_unique(array_column($tags, 'connection'))));
    }

    public function testProviderKeepsAttachingTheListenersWhenTheEventManagerIsNotTheOneOfTheConnection(): void
    {
        // an entity manager with its own event manager: a listener of the connection would not see its flushes
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager', 'app.entity_manager']);
        $this->setDefinition('app.entity_manager', new Definition(null, [new Reference('doctrine.dbal.default_connection'), new Reference('app.orm_configuration'), new Reference('app.event_manager')]));

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
        $this->assertNotContains('disableListenerAttachment', array_column($this->container->getDefinition(DoctrineProvider::class)->getMethodCalls(), 0));
        $this->assertStringContainsString('"app.entity_manager" does not use the event manager of a DoctrineBundle connection', implode("\n", $this->container->getCompiler()->getLog()));
    }

    public function testProviderKeepsAttachingTheListenersWhenTheEventManagerIsMissing(): void
    {
        // the entity manager creates its own event manager
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->getDefinition('doctrine.orm.default_entity_manager')->setArguments([new Reference('doctrine.dbal.default_connection'), new Reference('doctrine.orm.default_configuration')]);

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
    }

    public function testProviderKeepsAttachingTheListenersWhenTheEventManagerIsNotAReference(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->getDefinition('doctrine.orm.default_entity_manager')->setArguments([new Reference('doctrine.dbal.default_connection'), new Reference('doctrine.orm.default_configuration'), new Definition(EventManager::class)]);

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
    }

    public function testProviderKeepsAttachingTheListenersWhenAnEntityManagerIsUnknown(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager', 'doctrine.orm.unknown_entity_manager']);

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
        $this->assertNotContains('disableListenerAttachment', array_column($this->container->getDefinition(DoctrineProvider::class)->getMethodCalls(), 0));
    }

    public function testProviderKeepsAttachingTheListenersWhenAConnectionIsUnknown(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->getDefinition('doctrine.orm.default_entity_manager')->setArguments([new Reference('custom_connection'), new Reference('doctrine.orm.default_configuration'), new Reference('doctrine.dbal.default_connection.event_manager')]);

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
    }

    public function testProviderKeepsAttachingTheListenersWhenTheConnectionIsNotAReference(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->getDefinition('doctrine.orm.default_entity_manager')->setArguments([new Definition(Connection::class), new Reference('doctrine.orm.default_configuration'), new Reference('doctrine.dbal.default_connection.event_manager')]);

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
    }

    public function testPassDoesNothingWithoutDoctrineBundle(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->getParameterBag()->remove('doctrine.connections');

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
    }

    public function testPassDoesNothingWithoutTheDoctrineProviderConfiguration(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->getParameterBag()->remove('dh_auditor.provider.doctrine.configuration');

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
    }

    public function testPassDoesNothingWithoutAuditingServices(): void
    {
        $this->setUpDoctrine([]);

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
        $this->assertNotContains('disableListenerAttachment', array_column($this->container->getDefinition(DoctrineProvider::class)->getMethodCalls(), 0));
    }

    public function testPassDoesNothingWithoutTheDoctrineProvider(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->removeDefinition(DoctrineProvider::class);

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
    }

    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RegisterDoctrineListenersCompilerPass());
    }

    /**
     * @param list<string> $auditingServices
     */
    private function setUpDoctrine(array $auditingServices): void
    {
        $this->setParameter('dh_auditor.provider.doctrine.configuration', ['auditing_services' => $auditingServices, 'storage_services' => []]);
        $this->setParameter('doctrine.connections', [
            'default' => 'doctrine.dbal.default_connection',
            'second' => 'doctrine.dbal.second_connection',
            'other' => 'doctrine.dbal.other_connection',
        ]);

        // as DoctrineBundle defines them: connection, configuration, event manager of the connection
        foreach (['default' => 'default', 'second' => 'second', 'third' => 'default'] as $entityManager => $connection) {
            $this->setDefinition(\sprintf('doctrine.orm.%s_entity_manager', $entityManager), new Definition(null, [
                new Reference(\sprintf('doctrine.dbal.%s_connection', $connection)),
                new Reference(\sprintf('doctrine.orm.%s_configuration', $entityManager)),
                new Reference(\sprintf('doctrine.dbal.%s_connection.event_manager', $connection)),
            ]));
        }

        $provider = new Definition(DoctrineProvider::class);
        $provider->addMethodCall('setLogger');
        $provider->addMethodCall('registerAuditingService');
        $this->setDefinition(DoctrineProvider::class, $provider);
    }
}
