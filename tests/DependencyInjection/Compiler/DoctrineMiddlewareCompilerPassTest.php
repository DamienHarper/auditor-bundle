<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\Auditor\Configuration;
use DH\Auditor\Provider\Doctrine\Auditing\Logger\Middleware\DHMiddleware;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\AuditorBundle\DependencyInjection\Compiler\DoctrineProviderConfigurationCompilerPass;
use DH\AuditorBundle\DependencyInjection\DHAuditorExtension;
use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Doctrine\DBAL\Driver\Middleware;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * @internal
 *
 * @small
 */
final class DoctrineMiddlewareCompilerPassTest extends AbstractCompilerPassTestCase
{
    public function testCompilerPass(): void
    {
        if (!interface_exists(Middleware::class) || !class_exists(DHMiddleware::class)) {
            self::markTestSkipped('DHMiddleware isn\'t supported');
        }
        $this->container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $this->container->setParameter('kernel.debug', false);
        $this->container->setParameter('kernel.bundles', []);
        $doctrineConfig = [
            'dbal' => [
                'default_connection' => 'default',
                'connections' => [
                    'default' => [],
                ],
            ],
            'orm' => [
                'auto_mapping' => true,
            ],
        ];
        $this->setParameter('doctrine', $doctrineConfig);

        $DHConfig = [
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
        $this->setParameter('dh_auditor.configuration', $DHConfig);

        $auditorService = new Definition();
        $this->setDefinition(Configuration::class, $auditorService);
        $this->container->loadFromExtension('doctrine', $doctrineConfig);
        $this->container->loadFromExtension('dh_auditor', $DHConfig);
        $this->compile();

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            'doctrine.dbal.default_connection.configuration',
            'setMiddlewares',
            [[new ChildDefinition('doctrine.dbal.dh_middleware')]]
        );
    }

    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $this->container->registerExtension(new DoctrineExtension());
        $this->container->registerExtension(new DHAuditorExtension());
        $container->addCompilerPass(new DoctrineProviderConfigurationCompilerPass());
    }
}
