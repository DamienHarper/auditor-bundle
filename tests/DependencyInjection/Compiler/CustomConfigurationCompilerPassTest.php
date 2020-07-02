<?php

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\AuditorBundle\DependencyInjection\Compiler\AddProviderCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\CustomConfigurationCompilerPass;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 */
final class CustomConfigurationCompilerPassTest extends AbstractCompilerPassTestCase
{
    public function testCompilerPass(): void
    {
        $config = [
            'table_prefix' => '',
            'table_suffix' => '_audit',
            'ignored_columns' => [
                0 => 'createdAt',
                1 => 'updatedAt',
            ],
            'entities' => [
                'DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Author' => [
                    'enabled' => true,
                ],
                'DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Post' => [
                    'enabled' => true,
                ],
                'DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Comment' => [
                    'enabled' => true,
                ],
                'DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Tag' => [
                    'enabled' => true,
                ],
            ],
            'storage_services' => [
                0 => '@doctrine.orm.default_entity_manager',
            ],
            'auditing_services' => [
                0 => '@doctrine.orm.default_entity_manager',
            ],
            'viewer' => true,
            'storage_mapper' => null,
            'role_checker' => 'dh_auditor.role_checker',
            'user_provider' => 'dh_auditor.user_provider',
            'security_provider' => 'dh_auditor.security_provider',
        ];
        $this->setParameter('dh_auditor.provider.doctrine.configuration', $config);

        $doctrineProviderService = new Definition();
        $this->setDefinition(DoctrineProvider::class, $doctrineProviderService);

        $this->compile();

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            DoctrineProvider::class,
            'setRoleChecker',
            [new Reference('dh_auditor.role_checker')]
        );

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            DoctrineProvider::class,
            'setUserProvider',
            [new Reference('dh_auditor.user_provider')]
        );

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            DoctrineProvider::class,
            'setSecurityProvider',
            [new Reference('dh_auditor.security_provider')]
        );
    }

    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AddProviderCompilerPass());
        $container->addCompilerPass(new CustomConfigurationCompilerPass());
    }
}
