<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\AuditorBundle\DependencyInjection\Compiler\DoctrineProviderConfigurationCompilerPass;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 *
 * @small
 */
final class StorageConfigurationCompilerPassTest extends AbstractCompilerPassTestCase
{
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DoctrineProviderConfigurationCompilerPass());
    }

    //    public function testCompilerPass(): void
    //    {
    //        $this->compile();
    //
    //        $serviceId = 'dh_auditor.provider.doctrine.storage_services.doctrine.orm.default_entity_manager';
    //        $this->assertContainerBuilderHasAlias($serviceId, StorageService::class);
    //        $this->assertContainerBuilderHasService($serviceId, StorageService::class);
    //    }
}
