<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\Auditor\Configuration;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\AuditorBundle\DependencyInjection\Compiler\AddProviderCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\CustomConfigurationCompilerPass;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @internal
 *
 * @small
 *
 * @coversNothing
 */
final class CustomConfigurationCompilerPassTest extends AbstractCompilerPassTestCase
{
    public function testCompilerPass(): void
    {
        $config = [
            'enabled' => true,
            'timezone' => 'UTC',
            'user_provider' => 'dh_auditor.user_provider',
            'security_provider' => 'dh_auditor.security_provider',
            'role_checker' => 'dh_auditor.role_checker',
            'providers' => [
                'doctrine' => [
                    'table_prefix' => '',
                    'table_suffix' => '_audit',
                    'ignored_columns' => [
                        0 => 'createdAt',
                        1 => 'updatedAt',
                    ],
                    'entities' => [
                        Author::class => [
                            'enabled' => true,
                        ],
                        Post::class => [
                            'enabled' => true,
                        ],
                        Comment::class => [
                            'enabled' => true,
                        ],
                        Tag::class => [
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
                ],
            ],
        ];
        $this->setParameter('dh_auditor.configuration', $config);

        $auditorService = new Definition();
        $this->setDefinition(Configuration::class, $auditorService);

        $this->compile();

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            Configuration::class,
            'setRoleChecker',
            [new Reference('dh_auditor.role_checker')]
        );

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            Configuration::class,
            'setUserProvider',
            [new Reference('dh_auditor.user_provider')]
        );

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            Configuration::class,
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
