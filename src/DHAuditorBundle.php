<?php

declare(strict_types=1);

namespace DH\AuditorBundle;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\AttributeLoader;
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
use DH\AuditorBundle\Command\ClearActivityCacheCommand;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\DependencyInjection\Compiler\DoctrineMiddlewareCompilerPass;
use DH\AuditorBundle\Event\ConsoleEventSubscriber;
use DH\AuditorBundle\Event\ViewerEventSubscriber;
use DH\AuditorBundle\ExtraData\ExtraDataProvider;
use DH\AuditorBundle\Routing\RoutingLoader;
use DH\AuditorBundle\Security\RoleChecker;
use DH\AuditorBundle\Security\SecurityProvider;
use DH\AuditorBundle\Twig\TimeAgoExtension;
use DH\AuditorBundle\User\ConsoleUserProvider;
use DH\AuditorBundle\User\UserProvider;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @see Tests\DHAuditorBundleTest
 */
class DHAuditorBundle extends AbstractBundle
{
    #[\Override]
    public function getPath(): string
    {
        return __DIR__;
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new DoctrineMiddlewareCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
    }

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
            ->scalarNode('extra_data_provider')
            ->defaultNull()
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

    /**
     * @param array{enabled: bool, timezone: string, user_provider: string, security_provider: string, role_checker: string, extra_data_provider: ?string, providers: array<string, array<string, mixed>>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        // Auditor core configuration
        $auditorConfig = $config;
        unset($auditorConfig['providers']);
        $builder->setParameter('dh_auditor.configuration', $auditorConfig);

        // Auditor Configuration service
        $configDefinition = $services->set(Configuration::class)
            ->args(['%dh_auditor.configuration%'])
            ->call('setUserProvider', [new Reference($config['user_provider'])])
            ->call('setSecurityProvider', [new Reference($config['security_provider'])])
            ->call('setRoleChecker', [new Reference($config['role_checker'])])
        ;

        if (null !== $config['extra_data_provider']) {
            $configDefinition->call('setExtraDataProvider', [new Reference($config['extra_data_provider'])]);
        }

        // Auditor service
        $services->set(Auditor::class)
            ->args([
                new Reference(Configuration::class),
                new Reference('event_dispatcher'),
            ])
        ;

        // Load providers
        foreach ($config['providers'] as $providerName => $providerConfig) {
            $builder->setParameter('dh_auditor.provider.'.$providerName.'.configuration', $providerConfig);
            $builder->registerAliasForArgument('dh_auditor.provider.'.$providerName, ProviderInterface::class, \sprintf('%sProvider', $providerName));

            if ('doctrine' === $providerName) {
                /** @var array{storage_services: list<string>, auditing_services: list<string>, viewer: array<string, mixed>|bool} $providerConfig */
                $this->loadDoctrineProvider($services, $builder, $providerConfig);
            }
        }

        // Bundle services
        $this->loadBundleServices($services);
    }

    /**
     * @param array{storage_services: list<string>, auditing_services: list<string>, viewer: array<string, mixed>|bool} $config
     */
    private function loadDoctrineProvider(ServicesConfigurator $services, ContainerBuilder $builder, array $config): void
    {
        // DoctrineProvider Configuration
        $services->set(DoctrineProviderConfiguration::class)
            ->args(['%dh_auditor.provider.doctrine.configuration%'])
        ;

        // DoctrineProvider
        $services->set(DoctrineProvider::class)
            ->args([new Reference(DoctrineProviderConfiguration::class)])
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('dh_auditor.provider')
        ;

        // Register the provider with Auditor
        $builder->getDefinition(Auditor::class)
            ->addMethodCall('registerProvider', [new Reference(DoctrineProvider::class)])
        ;

        $services->alias('dh_auditor.provider.doctrine', DoctrineProvider::class);

        // Register storage services
        /** @var list<string> $storageServices */
        $storageServices = $config['storage_services'];
        foreach (array_unique($storageServices) as $entityManagerName) {
            $entityManagerName = str_replace('@', '', (string) $entityManagerName);
            $serviceId = 'dh_auditor.provider.doctrine.storage_services.'.$entityManagerName;

            $services->set($serviceId, StorageService::class)
                ->args([$serviceId, new Reference($entityManagerName)])
            ;

            $builder->getDefinition(DoctrineProvider::class)
                ->addMethodCall('registerStorageService', [new Reference($serviceId)])
            ;
        }

        // Register auditing services
        /** @var list<string> $auditingServices */
        $auditingServices = $config['auditing_services'];
        foreach (array_unique($auditingServices) as $entityManagerName) {
            $entityManagerName = str_replace('@', '', (string) $entityManagerName);
            $serviceId = 'dh_auditor.provider.doctrine.auditing_services.'.$entityManagerName;

            $services->set($serviceId, AuditingService::class)
                ->args([$serviceId, new Reference($entityManagerName)])
            ;

            $services->set(AttributeLoader::class)
                ->args([new Reference($entityManagerName)])
            ;

            $builder->getDefinition(DoctrineProvider::class)
                ->addMethodCall('registerAuditingService', [new Reference($serviceId)])
            ;
        }

        // Reader
        $services->set(Reader::class)
            ->args([new Reference(DoctrineProvider::class)])
        ;

        // Routing loader (uses #[AutoconfigureTag('routing.loader')] attribute)
        $services->set(RoutingLoader::class)
            ->args([
                new Reference('routing.loader.attribute'),
                '%dh_auditor.provider.doctrine.configuration%',
            ])
            ->autoconfigure()
        ;

        // Doctrine event listeners
        $services->set(CreateSchemaListener::class)
            ->args([new Reference(DoctrineProvider::class)])
            ->tag('doctrine.event_listener', ['event' => 'postGenerateSchemaTable'])
        ;

        $services->set(TableSchemaListener::class)
            ->args([new Reference(DoctrineProvider::class)])
            ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata'])
        ;

        // Commands
        $services->set(CleanAuditLogsCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:clean'])
        ;

        $services->set(UpdateSchemaCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:schema:update'])
        ;

        // Activity Graph Provider
        $activityGraphProviderRef = null;
        if (\is_array($config['viewer']) && ($config['viewer']['activity_graph']['enabled'] ?? false)) {
            /** @var array{enabled: bool, days: int, layout: string, cache: array{enabled: bool, pool: string, ttl: int}} $activityGraphConfig */
            $activityGraphConfig = $config['viewer']['activity_graph'];

            $cachePoolRef = $activityGraphConfig['cache']['enabled']
                ? new Reference($activityGraphConfig['cache']['pool'])
                : null;

            $services->set(ActivityGraphProvider::class)
                ->args([
                    $activityGraphConfig['days'],
                    $activityGraphConfig['layout'],
                    $activityGraphConfig['cache']['enabled'],
                    $activityGraphConfig['cache']['ttl'],
                    $cachePoolRef,
                ])
            ;

            $activityGraphProviderRef = new Reference(ActivityGraphProvider::class);
        }

        // Clear Activity Cache Command
        $services->set(ClearActivityCacheCommand::class)
            ->args([$activityGraphProviderRef])
            ->tag('console.command', ['command' => 'audit:cache:clear'])
        ;
    }

    private function loadBundleServices(ServicesConfigurator $services): void
    {
        // ViewerController (uses #[AsController] attribute)
        $services->set(ViewerController::class)
            ->args([
                new Reference('twig'),
                new Reference(ActivityGraphProvider::class, ContainerBuilder::NULL_ON_INVALID_REFERENCE),
            ])
            ->autoconfigure()
        ;

        // UserProvider (autowired)
        $services->set(UserProvider::class)->autowire();
        $services->alias('dh_auditor.user_provider', UserProvider::class);

        // ConsoleUserProvider
        $services->set(ConsoleUserProvider::class);

        // SecurityProvider (autowired)
        $services->set(SecurityProvider::class)->autowire();
        $services->alias('dh_auditor.security_provider', SecurityProvider::class);

        // RoleChecker (autowired)
        $services->set(RoleChecker::class)->autowire();
        $services->alias('dh_auditor.role_checker', RoleChecker::class);

        // ExtraDataProvider (autowired) — available but NOT wired by default;
        // users opt in by setting extra_data_provider: dh_auditor.extra_data_provider
        $services->set(ExtraDataProvider::class)->autowire();
        $services->alias('dh_auditor.extra_data_provider', ExtraDataProvider::class);

        // Event listeners (using #[AsEventListener] attributes)
        $services->set(ViewerEventSubscriber::class)
            ->args([new Reference(Auditor::class)])
            ->autoconfigure()
        ;

        $services->set(ConsoleEventSubscriber::class)
            ->args([
                new Reference(ConsoleUserProvider::class),
                new Reference(Configuration::class),
                new Reference('dh_auditor.user_provider'),
            ])
            ->autoconfigure()
        ;

        // Twig extension (uses #[AsTwigFilter] attribute)
        $services->set(TimeAgoExtension::class)
            ->args([new Reference('translator')])
            ->autoconfigure()
        ;
    }

    /**
     * @param array<string, mixed> $v
     *
     * @return array<string, mixed>
     */
    private function normalizeProvidersConfig(array $v): array
    {
        if (!\array_key_exists('doctrine', $v)) {
            $v['doctrine'] = [];
        }

        /** @var array<string, mixed> $doctrine */
        $doctrine = $v['doctrine'];

        // "table_prefix" is empty by default.
        if (!\array_key_exists('table_prefix', $doctrine) || !\is_string($doctrine['table_prefix'])) {
            $doctrine['table_prefix'] = '';
        }

        // "table_suffix" is "_audit" by default.
        if (!\array_key_exists('table_suffix', $doctrine) || !\is_string($doctrine['table_suffix'])) {
            $doctrine['table_suffix'] = '_audit';
        }

        // "entities" are "enabled" by default.
        if (\array_key_exists('entities', $doctrine) && \is_array($doctrine['entities'])) {
            /**
             * @var string                    $entity
             * @var null|array<string, mixed> $options
             */
            foreach ($doctrine['entities'] as $entity => $options) {
                if (null === $options) {
                    $doctrine['entities'][$entity] = ['enabled' => true];
                } elseif (!\array_key_exists('enabled', $options)) {
                    $doctrine['entities'][$entity]['enabled'] = true;
                }
            }
        }

        // "doctrine.orm.default_entity_manager" is the default "storage_services"
        if (\array_key_exists('storage_services', $doctrine) && \is_string($doctrine['storage_services'])) {
            $doctrine['storage_services'] = [$doctrine['storage_services']];
        } elseif (!\array_key_exists('storage_services', $doctrine) || !\is_array($doctrine['storage_services'])) {
            $doctrine['storage_services'] = ['doctrine.orm.default_entity_manager'];
        }

        // "doctrine.orm.default_entity_manager" is the default "auditing_services"
        if (\array_key_exists('auditing_services', $doctrine) && \is_string($doctrine['auditing_services'])) {
            $doctrine['auditing_services'] = [$doctrine['auditing_services']];
        } elseif (!\array_key_exists('auditing_services', $doctrine) || !\is_array($doctrine['auditing_services'])) {
            $doctrine['auditing_services'] = ['doctrine.orm.default_entity_manager'];
        }

        // "viewer" is disabled by default
        $defaultActivityGraphOptions = [
            'enabled' => true,
            'days' => 30,
            'layout' => 'bottom',  // 'bottom' (Option B) or 'inline' (Option A)
            'cache' => [
                'enabled' => true,
                'pool' => 'cache.app',
                'ttl' => 300,
            ],
        ];
        $defaultViewerOptions = [
            'enabled' => false,
            'page_size' => Reader::PAGE_SIZE,
            'activity_graph' => $defaultActivityGraphOptions,
        ];
        if (\array_key_exists('viewer', $doctrine)) {
            if (\is_array($doctrine['viewer'])) {
                if (
                    !\array_key_exists('enabled', $doctrine['viewer'])
                    || !\is_bool($doctrine['viewer']['enabled'])
                ) {
                    $doctrine['viewer']['enabled'] = false;
                }

                if (
                    !\array_key_exists('page_size', $doctrine['viewer'])
                    || !\is_int($doctrine['viewer']['page_size'])
                ) {
                    $doctrine['viewer']['page_size'] = Reader::PAGE_SIZE;
                }

                // Normalize activity_graph options
                /** @var array<string, mixed> $activityGraphInput */
                $activityGraphInput = $doctrine['viewer']['activity_graph'] ?? [];
                $doctrine['viewer']['activity_graph'] = $this->normalizeActivityGraphConfig(
                    $activityGraphInput,
                    $defaultActivityGraphOptions
                );
            } elseif (!\is_bool($doctrine['viewer'])) {
                $doctrine['viewer'] = $defaultViewerOptions;
            } else {
                // viewer is a boolean, convert to array with defaults
                $doctrine['viewer'] = array_merge($defaultViewerOptions, ['enabled' => $doctrine['viewer']]);
            }
        } else {
            $doctrine['viewer'] = $defaultViewerOptions;
        }

        // "storage_mapper" is null by default
        if (!\array_key_exists('storage_mapper', $doctrine) || !\is_string($doctrine['storage_mapper'])) {
            $doctrine['storage_mapper'] = null;
        }

        $v['doctrine'] = $doctrine;

        return $v;
    }

    /**
     * @param array<string, mixed>                                                                                 $config
     * @param array{enabled: bool, days: int, layout: string, cache: array{enabled: bool, pool: string, ttl: int}} $defaults
     *
     * @return array{enabled: bool, days: int, layout: string, cache: array{enabled: bool, pool: string, ttl: int}}
     */
    private function normalizeActivityGraphConfig(array $config, array $defaults): array
    {
        /** @var array<string, mixed> $configCache */
        $configCache = $config['cache'] ?? [];

        $days = $config['days'] ?? $defaults['days'];
        $ttl = $configCache['ttl'] ?? $defaults['cache']['ttl'];
        $pool = $configCache['pool'] ?? $defaults['cache']['pool'];
        $layout = $config['layout'] ?? $defaults['layout'];

        // Validate layout value
        $validLayouts = ['bottom', 'inline'];
        if (!\is_string($layout) || !\in_array($layout, $validLayouts, true)) {
            $layout = $defaults['layout'];
        }

        return [
            'enabled' => (bool) ($config['enabled'] ?? $defaults['enabled']),
            'days' => max(1, min(30, is_numeric($days) ? (int) $days : $defaults['days'])),
            'layout' => $layout,
            'cache' => [
                'enabled' => (bool) ($configCache['enabled'] ?? $defaults['cache']['enabled']),
                'pool' => \is_string($pool) ? $pool : $defaults['cache']['pool'],
                'ttl' => max(0, is_numeric($ttl) ? (int) $ttl : $defaults['cache']['ttl']),
            ],
        ];
    }
}
