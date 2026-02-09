<?php

declare(strict_types=1);

namespace DH\AuditorBundle;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration;
use DH\Auditor\Provider\Doctrine\Auditing\Annotation\AnnotationLoader;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Command\CleanAuditLogsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\UpdateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Event\TableSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Provider\ProviderInterface;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\Event\ConsoleEventSubscriber;
use DH\AuditorBundle\Event\ViewerEventSubscriber;
use DH\AuditorBundle\Routing\RoutingLoader;
use DH\AuditorBundle\Security\RoleChecker;
use DH\AuditorBundle\Security\SecurityProvider;
use DH\AuditorBundle\User\ConsoleUserProvider;
use DH\AuditorBundle\User\UserProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @see Tests\DHAuditorBundleTest
 */
class DHAuditorBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()
                ->scalarNode('timezone')
                    ->defaultValue('UTC')
                ->end()
                ->scalarNode('user_provider')
                    ->defaultValue('dh_auditor.user_provider')
                ->end()
                ->scalarNode('security_provider')
                    ->defaultValue('dh_auditor.security_provider')
                ->end()
                ->scalarNode('role_checker')
                    ->defaultValue('dh_auditor.role_checker')
                ->end()
                ->arrayNode('providers')
                    ->requiresAtLeastOneElement()
                    ->useAttributeAsKey('name')
                    ->variablePrototype()
                        ->validate()
                            ->ifEmpty()
                            ->thenInvalid('Invalid provider configuration %s')
                        ->end()
                    ->end()
                    ->validate()
                        ->always()
                        ->then($this->normalizeProvidersConfig(...))
                    ->end()
                ->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // Auditor core configuration
        $auditorConfig = $config;
        unset($auditorConfig['providers']);
        $builder->setParameter('dh_auditor.configuration', $auditorConfig);

        // Auditor Configuration service
        $services->set(Configuration::class)
            ->args(['%dh_auditor.configuration%'])
            ->call('setUserProvider', [new Reference($config['user_provider'])])
            ->call('setSecurityProvider', [new Reference($config['security_provider'])])
            ->call('setRoleChecker', [new Reference($config['role_checker'])]);

        // Auditor service
        $services->set(Auditor::class)
            ->args([
                new Reference(Configuration::class),
                new Reference('event_dispatcher'),
            ]);

        // Load providers
        foreach ($config['providers'] as $providerName => $providerConfig) {
            $builder->setParameter('dh_auditor.provider.'.$providerName.'.configuration', $providerConfig);
            $builder->registerAliasForArgument('dh_auditor.provider.'.$providerName, ProviderInterface::class, \sprintf('%sProvider', $providerName));

            if ('doctrine' === $providerName) {
                $this->loadDoctrineProvider($services, $builder, $providerConfig);
            }
        }

        // Bundle services
        $this->loadBundleServices($services, $builder);
    }

    private function loadDoctrineProvider($services, ContainerBuilder $builder, array $config): void
    {
        // DoctrineProvider Configuration
        $services->set(DoctrineProviderConfiguration::class)
            ->args(['%dh_auditor.provider.doctrine.configuration%']);

        // DoctrineProvider
        $services->set(DoctrineProvider::class)
            ->args([new Reference(DoctrineProviderConfiguration::class)])
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('dh_auditor.provider');

        $services->alias('dh_auditor.provider.doctrine', DoctrineProvider::class);

        // Register storage services
        foreach (array_unique($config['storage_services']) as $entityManagerName) {
            $entityManagerName = str_replace('@', '', $entityManagerName);
            $serviceId = 'dh_auditor.provider.doctrine.storage_services.'.$entityManagerName;

            $services->set($serviceId, StorageService::class)
                ->args([$serviceId, new Reference($entityManagerName)]);

            $builder->getDefinition(DoctrineProvider::class)
                ->addMethodCall('registerStorageService', [new Reference($serviceId)]);
        }

        // Register AuditorMiddleware
        $builder->register('doctrine.dbal.auditor_middleware', AuditorMiddleware::class);

        // Register auditing services
        foreach (array_unique($config['auditing_services']) as $entityManagerName) {
            $entityManagerName = str_replace('@', '', $entityManagerName);
            $serviceId = 'dh_auditor.provider.doctrine.auditing_services.'.$entityManagerName;

            $services->set($serviceId, AuditingService::class)
                ->args([$serviceId, new Reference($entityManagerName)]);

            $services->set(AnnotationLoader::class)
                ->args([new Reference($entityManagerName)]);

            $builder->getDefinition(DoctrineProvider::class)
                ->addMethodCall('registerAuditingService', [new Reference($serviceId)]);

            // Configure Auditor Middleware for this entity manager
            $this->configureAuditorMiddleware($builder, $entityManagerName);
        }

        // Reader
        $services->set(Reader::class)
            ->args([new Reference(DoctrineProvider::class)]);

        // Routing loader
        $services->set(RoutingLoader::class)
            ->args([
                new Reference('routing.loader.attribute'),
                '%dh_auditor.provider.doctrine.configuration%',
            ])
            ->tag('routing.loader');

        // Doctrine event listeners
        $services->set(CreateSchemaListener::class)
            ->args([new Reference(DoctrineProvider::class)])
            ->tag('doctrine.event_listener', ['event' => 'postGenerateSchemaTable']);

        $services->set(TableSchemaListener::class)
            ->args([new Reference(DoctrineProvider::class)])
            ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);

        // Commands
        $services->set(CleanAuditLogsCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:clean']);

        $services->set(UpdateSchemaCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:schema:update']);
    }

    private function loadBundleServices($services, ContainerBuilder $builder): void
    {
        // ViewerController
        $services->set(ViewerController::class)
            ->args([new Reference('twig')])
            ->tag('controller.service_arguments');

        // UserProvider
        $services->set(UserProvider::class)
            ->args([new Reference('security.token_storage')]);
        $services->alias('dh_auditor.user_provider', UserProvider::class);

        // ConsoleUserProvider
        $services->set(ConsoleUserProvider::class);

        // SecurityProvider
        $services->set(SecurityProvider::class)
            ->args([
                new Reference('request_stack'),
                new Reference('security.firewall.map'),
            ]);
        $services->alias('dh_auditor.security_provider', SecurityProvider::class);

        // RoleChecker
        $services->set(RoleChecker::class)
            ->args([
                new Reference('security.authorization_checker'),
                new Reference(DoctrineProvider::class),
            ]);
        $services->alias('dh_auditor.role_checker', RoleChecker::class);

        // Event subscribers
        $services->set(ViewerEventSubscriber::class)
            ->args([new Reference(Auditor::class)])
            ->tag('kernel.event_subscriber');

        $services->set(ConsoleEventSubscriber::class)
            ->args([
                new Reference(ConsoleUserProvider::class),
                new Reference(Configuration::class),
                new Reference('dh_auditor.user_provider'),
            ])
            ->tag('kernel.event_subscriber');
    }

    private function configureAuditorMiddleware(ContainerBuilder $builder, string $entityManagerName): void
    {
        if (!$builder->hasDefinition($entityManagerName)) {
            return;
        }

        $argument = $builder->getDefinition($entityManagerName)->getArgument(0);
        if (!$argument instanceof Reference) {
            return;
        }

        $connectionName = (string) $argument;
        $configurationName = $connectionName.'.configuration';

        if (!$builder->hasDefinition($configurationName)) {
            return;
        }

        $builder
            ->setDefinition(
                $connectionName.'.auditor_middleware',
                new ChildDefinition('doctrine.dbal.auditor_middleware')
            )
            ->addTag('doctrine.middleware');
    }

    private function normalizeProvidersConfig(array $v): array
    {
        if (!\array_key_exists('doctrine', $v)) {
            $v['doctrine'] = [];
        }

        // "table_prefix" is empty by default.
        if (!\array_key_exists('table_prefix', $v['doctrine']) || !\is_string($v['doctrine']['table_prefix'])) {
            $v['doctrine']['table_prefix'] = '';
        }

        // "table_suffix" is "_audit" by default.
        if (!\array_key_exists('table_suffix', $v['doctrine']) || !\is_string($v['doctrine']['table_suffix'])) {
            $v['doctrine']['table_suffix'] = '_audit';
        }

        // "entities" are "enabled" by default.
        if (\array_key_exists('entities', $v['doctrine']) && \is_array($v['doctrine']['entities'])) {
            foreach ($v['doctrine']['entities'] as $entity => $options) {
                if (null === $options || !\array_key_exists('enabled', $options)) {
                    $v['doctrine']['entities'][$entity]['enabled'] = true;
                }
            }
        }

        // "doctrine.orm.default_entity_manager" is the default "storage_services"
        if (\array_key_exists('storage_services', $v['doctrine']) && \is_string($v['doctrine']['storage_services'])) {
            $v['doctrine']['storage_services'] = [$v['doctrine']['storage_services']];
        } elseif (!\array_key_exists('storage_services', $v['doctrine']) || !\is_array($v['doctrine']['storage_services'])) {
            $v['doctrine']['storage_services'] = ['doctrine.orm.default_entity_manager'];
        }

        // "doctrine.orm.default_entity_manager" is the default "auditing_services"
        if (\array_key_exists('auditing_services', $v['doctrine']) && \is_string($v['doctrine']['auditing_services'])) {
            $v['doctrine']['auditing_services'] = [$v['doctrine']['auditing_services']];
        } elseif (!\array_key_exists('auditing_services', $v['doctrine']) || !\is_array($v['doctrine']['auditing_services'])) {
            $v['doctrine']['auditing_services'] = ['doctrine.orm.default_entity_manager'];
        }

        // "viewer" is disabled by default
        $defaultViewerOptions = [
            'enabled' => false,
            'page_size' => Reader::PAGE_SIZE,
        ];
        if (\array_key_exists('viewer', $v['doctrine'])) {
            if (\is_array($v['doctrine']['viewer'])) {
                if (
                    !\array_key_exists('enabled', $v['doctrine']['viewer']) ||
                    !\is_bool($v['doctrine']['viewer']['enabled'])
                ) {
                    $v['doctrine']['viewer']['enabled'] = false;
                }

                if (
                    !\array_key_exists('page_size', $v['doctrine']['viewer']) ||
                    !\is_int($v['doctrine']['viewer']['page_size'])
                ) {
                    $v['doctrine']['viewer']['page_size'] = Reader::PAGE_SIZE;
                }
            } elseif (!\is_bool($v['doctrine']['viewer'])) {
                $v['doctrine']['viewer'] = $defaultViewerOptions;
            }
        } else {
            $v['doctrine']['viewer'] = $defaultViewerOptions;
        }

        // "storage_mapper" is null by default
        if (!\array_key_exists('storage_mapper', $v['doctrine']) || !\is_string($v['doctrine']['storage_mapper'])) {
            $v['doctrine']['storage_mapper'] = null;
        }

        return $v;
    }
}
