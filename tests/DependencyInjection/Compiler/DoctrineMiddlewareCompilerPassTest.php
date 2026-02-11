<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\AuditorBundle\DependencyInjection\Compiler\DoctrineMiddlewareCompilerPass;
use Doctrine\DBAL\Driver\Middleware;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
#[Small]
final class DoctrineMiddlewareCompilerPassTest extends AbstractCompilerPassTestCase
{
    public function testCompilerPassDoesNothingWithoutDoctrineConfiguration(): void
    {
        $this->compile();

        // No middleware should be registered without doctrine configuration
        $this->assertContainerBuilderNotHasService('doctrine.dbal.auditor_middleware');
    }

    public function testCompilerPassRegistersMiddleware(): void
    {
        if (!interface_exists(Middleware::class) || !class_exists(AuditorMiddleware::class)) {
            self::markTestSkipped("AuditorMiddleware isn't supported");
        }

        // Setup doctrine configuration parameter
        $config = [
            'auditing_services' => ['doctrine.orm.default_entity_manager'],
            'storage_services' => ['doctrine.orm.default_entity_manager'],
        ];
        $this->setParameter('dh_auditor.provider.doctrine.configuration', $config);

        // Setup entity manager definition with connection reference
        $entityManagerDefinition = new Definition();
        $entityManagerDefinition->setArguments([new Reference('doctrine.dbal.default_connection')]);
        $this->setDefinition('doctrine.orm.default_entity_manager', $entityManagerDefinition);

        // Setup connection configuration definition
        $connectionConfigDefinition = new Definition();
        $this->setDefinition('doctrine.dbal.default_connection.configuration', $connectionConfigDefinition);

        $this->compile();

        // Check that base middleware is registered
        $this->assertContainerBuilderHasService('doctrine.dbal.auditor_middleware');

        // Check that connection-specific middleware is registered with tag
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'doctrine.dbal.default_connection.auditor_middleware',
            'doctrine.middleware'
        );
    }

    public function testCompilerPassSkipsWhenEntityManagerNotFound(): void
    {
        if (!interface_exists(Middleware::class) || !class_exists(AuditorMiddleware::class)) {
            self::markTestSkipped("AuditorMiddleware isn't supported");
        }

        // Setup doctrine configuration parameter with non-existent entity manager
        $config = [
            'auditing_services' => ['doctrine.orm.non_existent_entity_manager'],
            'storage_services' => ['doctrine.orm.default_entity_manager'],
        ];
        $this->setParameter('dh_auditor.provider.doctrine.configuration', $config);

        $this->compile();

        // Base middleware should be registered
        $this->assertContainerBuilderHasService('doctrine.dbal.auditor_middleware');

        // But no connection-specific middleware should be registered
        $this->assertContainerBuilderNotHasService('doctrine.dbal.default_connection.auditor_middleware');
    }

    #[\Override]
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DoctrineMiddlewareCompilerPass());
    }
}
