<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\Auditing\Event\DoctrineSubscriber;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\AuditorBundle\DependencyInjection\Compiler\RegisterDoctrineListenersCompilerPass;
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
        $this->container->getDefinition('doctrine.orm.default_entity_manager')->setArguments([new Reference('custom_connection')]);

        $this->compile();

        $this->assertContainerBuilderNotHasService(RegisterDoctrineListenersCompilerPass::SUBSCRIBER_SERVICE_ID);
    }

    public function testProviderKeepsAttachingTheListenersWhenTheConnectionIsNotAReference(): void
    {
        $this->setUpDoctrine(['doctrine.orm.default_entity_manager']);
        $this->container->getDefinition('doctrine.orm.default_entity_manager')->setArguments([new Definition('Doctrine\\DBAL\\Connection')]);

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

        $this->setDefinition('doctrine.orm.default_entity_manager', new Definition(null, [new Reference('doctrine.dbal.default_connection')]));
        $this->setDefinition('doctrine.orm.second_entity_manager', new Definition(null, [new Reference('doctrine.dbal.second_connection')]));
        $this->setDefinition('doctrine.orm.third_entity_manager', new Definition(null, [new Reference('doctrine.dbal.default_connection')]));

        $provider = new Definition(DoctrineProvider::class);
        $provider->addMethodCall('setLogger');
        $provider->addMethodCall('registerAuditingService');
        $this->setDefinition(DoctrineProvider::class, $provider);
    }
}
