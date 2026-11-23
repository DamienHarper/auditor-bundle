<?php

declare(strict_types=1);

namespace DH\AuditorBundle;

use Cron\CronExpression;
use DH\Auditor\Attribute\AuditRoot;
use DH\Auditor\Auditor;
use DH\Auditor\Blame\BlameContextStack;
use DH\Auditor\Configuration;
use DH\Auditor\Contract\DiffLabelResolverInterface;
use DH\Auditor\Contract\FieldLabelResolverInterface;
use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\EventSubscriber\AuditEventSubscriber;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\RetentionPolicy;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\AttributeLoader;
use DH\Auditor\Provider\Doctrine\Auditing\Root\ObjectIdentifier;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Command\CleanAuditLogsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\ExportAuditLogsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\MigrateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\RebuildRootsCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\SnapshotCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Command\UpdateSchemaCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Event\TableSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Provider\ProviderInterface;
use DH\AuditorBundle\Attribute\AsChangeDescriber;
use DH\AuditorBundle\Command\ClearActivityCacheCommand;
use DH\AuditorBundle\Controller\ExportController;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\DependencyInjection\Compiler\DoctrineMiddlewareCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\RegisterChangeDescribersCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\RegisterDiffLabelResolversCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\RegisterDoctrineListenersCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\RegisterProvidersCompilerPass;
use DH\AuditorBundle\Event\ConsoleEventSubscriber;
use DH\AuditorBundle\Event\ViewerEventSubscriber;
use DH\AuditorBundle\ExtraData\ExtraDataProvider;
use DH\AuditorBundle\History\AuthorNameResolverInterface;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\History\ObjectUrlResolverInterface;
use DH\AuditorBundle\History\TranslatedFieldLabelResolver;
use DH\AuditorBundle\History\ValueFormatter;
use DH\AuditorBundle\History\ViewerObjectUrlResolver;
use DH\AuditorBundle\Messenger\BlameContextMiddleware;
use DH\AuditorBundle\Retention\PruneAuditLogsHandler;
use DH\AuditorBundle\Retention\RetentionSchedule;
use DH\AuditorBundle\Routing\RoutingLoader;
use DH\AuditorBundle\Security\RoleChecker;
use DH\AuditorBundle\Security\SecurityProvider;
use DH\AuditorBundle\Twig\DiffExtension;
use DH\AuditorBundle\Twig\HistoryExtension;
use DH\AuditorBundle\Twig\PhraseExtension;
use DH\AuditorBundle\Twig\TimeAgoExtension;
use DH\AuditorBundle\Twig\ViewableEntityExtension;
use DH\AuditorBundle\Twig\ViewerUrlExtension;
use DH\AuditorBundle\User\ConsoleUserProvider;
use DH\AuditorBundle\User\UserProvider;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use DH\AuditorBundle\Viewer\AuditViewerUrlGenerator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * @see Tests\DHAuditorBundleTest
 */
class DHAuditorBundle extends AbstractBundle
{
    /**
     * Options allowed in the "retention" block of an entity.
     */
    private const array RETENTION_KEYS = ['max_age', 'max_entries'];

    /**
     * Options of the doctrine provider that name an invokable service, with the configuration method that sets it.
     */
    private const array CALLABLE_SERVICE_OPTIONS = [
        'ignored_columns_resolver' => 'setIgnoredColumnsResolver',
        'root_resolver' => 'setRootResolver',
        'entity_label_resolver' => 'setEntityLabelResolver',
        'read_scope' => 'setReadScope',
    ];

    #[\Override]
    public function getPath(): string
    {
        return __DIR__;
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->registerForAutoconfiguration(DiffLabelResolverInterface::class)
            ->addTag('dh_auditor.diff_label_resolver')
        ;

        $container->addCompilerPass(new DoctrineMiddlewareCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        // before the registration of the Doctrine listeners by DoctrineBundle (priority 0)
        $container->addCompilerPass(new RegisterDoctrineListenersCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        $container->addCompilerPass(new RegisterProvidersCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new RegisterDiffLabelResolversCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);

        $container->registerAttributeForAutoconfiguration(AsChangeDescriber::class, static function (ChildDefinition $definition, AsChangeDescriber $attribute): void {
            $definition->addTag(RegisterChangeDescribersCompilerPass::TAG, ['entity' => $attribute->entity, 'field' => $attribute->field]);
        });
        $container->addCompilerPass(new RegisterChangeDescribersCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
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
            ->defaultValue([])
            ->useAttributeAsKey('name')
            ->variablePrototype()
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

        // Blame context stack, held by the auditor configuration: its current context is written in the "via" key of
        // the blame JSON. Reset between two messages of a worker or two requests of a FrankenPHP / RoadRunner worker.
        $services->set(BlameContextStack::class)
            ->factory([new Reference(Configuration::class), 'getBlameContextStack'])
            ->tag('kernel.reset', ['method' => 'reset'])
        ;
        $services->alias('dh_auditor.blame_context_stack', BlameContextStack::class);

        // Auditor service
        // The AuditEventSubscriber is declared in the container below instead of being attached by the
        // Auditor constructor: the compiled event dispatcher deprecates runtime addListener() calls (Symfony 8.2).
        $services->set(Auditor::class)
            ->args([
                new Reference(Configuration::class),
                new Reference('event_dispatcher'),
                false,
            ])
        ;

        $services->set(AuditEventSubscriber::class)
            ->args([new Reference(Auditor::class)])
            ->tag('kernel.event_listener', [
                'event' => LifecycleEvent::class,
                'method' => '__invoke',
                'priority' => AuditEventSubscriber::PRIORITY,
            ])
        ;

        // Default viewer state (overridden by providers that support the viewer)
        $builder->setParameter('dh_auditor.viewer_enabled', false);

        // Load providers
        foreach ($config['providers'] as $providerName => $providerConfig) {
            // Scheduled pruning settings belong to the bundle, not to the provider configuration; the options of
            // CALLABLE_SERVICE_OPTIONS are services, set on the provider configuration by method calls.
            $retentionConfig = null;
            $callableServices = [];
            if ('doctrine' === $providerName) {
                /** @var array{schedule: ?string, default_max_age: ?string} $retentionConfig */
                $retentionConfig = $providerConfig['retention'];
                unset($providerConfig['retention']);

                foreach (array_keys(self::CALLABLE_SERVICE_OPTIONS) as $option) {
                    $serviceId = $providerConfig[$option] ?? null;
                    if (\is_string($serviceId)) {
                        $callableServices[$option] = $serviceId;
                    }

                    unset($providerConfig[$option]);
                }
            }

            $builder->setParameter('dh_auditor.provider.'.$providerName.'.configuration', $providerConfig);

            if ('doctrine' === $providerName) {
                /** @var array{storage_services: list<string>, auditing_services: list<string>, viewer: array<string, mixed>|bool} $providerConfig */
                $this->loadDoctrineProvider($services, $builder, $providerConfig, $callableServices);
                \assert(null !== $retentionConfig);
                $this->loadScheduledRetention($services, $retentionConfig);
                // The alias dh_auditor.provider.doctrine is defined by loadDoctrineProvider(),
                // so we can register a named argument alias here.
                $builder->registerAliasForArgument('dh_auditor.provider.doctrine', ProviderInterface::class, 'doctrineProvider');
            }
        }

        // Bundle services
        $this->loadBundleServices($services);
    }

    /**
     * @param array{storage_services: list<string>, auditing_services: list<string>, viewer: array<string, mixed>|bool} $config
     * @param array<string, string>                                                                                     $callableServices option => service ID
     */
    private function loadDoctrineProvider(ServicesConfigurator $services, ContainerBuilder $builder, array $config, array $callableServices): void
    {
        // DoctrineProvider Configuration
        $configurationDefinition = $services->set(DoctrineProviderConfiguration::class)
            ->args(['%dh_auditor.provider.doctrine.configuration%'])
        ;

        foreach ($callableServices as $option => $serviceId) {
            $configurationDefinition->call(self::CALLABLE_SERVICE_OPTIONS[$option], [new Reference($serviceId)]);
        }

        // DoctrineProvider — tagged so RegisterProvidersCompilerPass picks it up automatically.
        // The compiler pass also injects Auditor via setAuditor() on this definition, ensuring
        // the Auditor reference is set as soon as DoctrineProvider is instantiated regardless
        // of service resolution order.
        // The logger reports the failures that cannot be thrown (e.g. audit rows left behind after a rollback);
        // with MonologBundle, the "monolog.logger" tag routes it to the "auditor" channel. Without any logger
        // service, the call is dropped and the provider keeps its NullLogger.
        $services->set(DoctrineProvider::class)
            ->args([new Reference(DoctrineProviderConfiguration::class)])
            ->call('setLogger', [new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)])
            ->tag('dh_auditor.provider')
            ->tag('kernel.reset', ['method' => 'reset'])
            ->tag('monolog.logger', ['channel' => 'auditor'])
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

        // Twig extension (uses #[AsTwigFunction] attribute)
        $services->set(ViewableEntityExtension::class)
            ->args([new Reference(DoctrineProvider::class)])
            ->autoconfigure()
        ;

        // Viewer URL generator and its Twig functions (dh_auditor_entity_url(), …)
        $services->set(AuditViewerUrlGenerator::class)
            ->args([
                new Reference(DoctrineProvider::class),
                new Reference('router'),
                '%dh_auditor.viewer_enabled%',
            ])
        ;

        $services->set(ViewerUrlExtension::class)
            ->args([new Reference(AuditViewerUrlGenerator::class)])
            ->autoconfigure()
        ;

        // Object references of history phrases lead to the history of the object in the viewer; decorate the resolver
        // to send some classes elsewhere
        $services->set(ViewerObjectUrlResolver::class)
            ->args([new Reference(AuditViewerUrlGenerator::class), new Reference(ViewableEntityExtension::class)])
        ;
        $services->alias(ObjectUrlResolverInterface::class, ViewerObjectUrlResolver::class);

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

        $services->set(ExportAuditLogsCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:export'])
        ;

        $services->set(UpdateSchemaCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:schema:update'])
        ;

        $services->set(MigrateSchemaCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:schema:migrate'])
        ;

        $services->set(SnapshotCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:snapshot'])
        ;

        $services->set(RebuildRootsCommand::class)
            ->call('setAuditor', [new Reference(Auditor::class)])
            ->tag('console.command', ['command' => 'audit:roots:rebuild'])
        ;

        // Identifier of an entity as the audit tables store it (object_id, root_id)
        $services->set(ObjectIdentifier::class)
            ->args([new Reference(DoctrineProvider::class)])
        ;

        // Field-level history in templates (dh_auditor_last_change(), dh_auditor_field_label()…)
        $services->set(HistoryExtension::class)
            ->args([new Reference(Reader::class), new Reference(DoctrineProvider::class), new Reference(FieldLabelResolverInterface::class), new Reference(ObjectIdentifier::class)])
            ->autoconfigure()
        ;

        // Expose viewer-enabled state as a container parameter so other services
        // (e.g. ViewerEventSubscriber) can react without coupling to DoctrineProvider.
        $isViewerEnabled = \is_array($config['viewer']) && ($config['viewer']['enabled'] ?? false);
        $builder->setParameter('dh_auditor.viewer_enabled', $isViewerEnabled);

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
        // ExportController (uses #[AsController] attribute; its route is only loaded when the viewer is enabled)
        $services->set(ExportController::class)
            ->autoconfigure()
        ;

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
            ->args([new Reference(Auditor::class), '%dh_auditor.viewer_enabled%'])
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

        // Messenger middleware blaming the user who dispatched a message (opt-in: add it to the buses)
        if (interface_exists(MiddlewareInterface::class)) {
            $services->set(BlameContextMiddleware::class)
                ->args([new Reference(Configuration::class)])
            ;
            $services->alias('dh_auditor.messenger.blame_context_middleware', BlameContextMiddleware::class);
        }

        // Twig extension (uses #[AsTwigFilter] attribute)
        $services->set(TimeAgoExtension::class)
            ->args([new Reference('translator')])
            ->autoconfigure()
        ;

        // Field labels from the "dh_auditor_fields" translation domain
        $services->set(TranslatedFieldLabelResolver::class)
            ->args([new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
        ;
        $services->alias(FieldLabelResolverInterface::class, TranslatedFieldLabelResolver::class);

        // History items told as phrases (|dh_auditor_phrase, |dh_auditor_author); the change describers are added by
        // RegisterChangeDescribersCompilerPass
        $services->set(ValueFormatter::class)
            ->args([new Reference('doctrine'), new Reference('translator')])
        ;
        $services->set(HistoryPhraser::class)
            ->args([
                '$translator' => new Reference('translator'),
                '$fieldLabelResolver' => new Reference(FieldLabelResolverInterface::class),
                '$values' => new Reference(ValueFormatter::class),
                '$describers' => null,
                '$authorNameResolver' => new Reference(AuthorNameResolverInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
        ;
        $services->set(PhraseExtension::class)
            ->args([new Reference(HistoryPhraser::class), new Reference(ObjectUrlResolverInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->autoconfigure()
        ;

        // Twig extension (uses #[AsTwigFunction] attribute)
        $services->set(DiffExtension::class)
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
        // Only normalize doctrine config when explicitly declared; do not inject a default
        // doctrine provider — other provider types are fully supported without it.
        if (!\array_key_exists('doctrine', $v)) {
            return $v;
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
                } else {
                    if (!\array_key_exists('enabled', $options)) {
                        $options['enabled'] = true;
                    }

                    $this->validateColumnsConfig($entity, $options);
                    $this->validateRootsConfig($entity, $options);
                    $doctrine['entities'][$entity] = $this->normalizeRetentionConfig($entity, $options);
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

        // "retention" (scheduled pruning) is disabled by default.
        $doctrine['retention'] = $this->normalizeScheduledRetentionConfig($doctrine['retention'] ?? null);

        // "ignored_columns_resolver", "root_resolver", "entity_label_resolver" and "read_scope" (service IDs) are null by default
        foreach (array_keys(self::CALLABLE_SERVICE_OPTIONS) as $option) {
            if (!\array_key_exists($option, $doctrine) || null === $doctrine[$option]) {
                $doctrine[$option] = null;
            } elseif (\is_string($doctrine[$option]) && '' !== $doctrine[$option]) {
                $doctrine[$option] = mb_ltrim($doctrine[$option], '@');
            } else {
                throw new \InvalidArgumentException(\sprintf('The "%s" option of the doctrine provider must be a service ID, %s given.', $option, get_debug_type($doctrine[$option])));
            }
        }

        // "storage_mapper" is null by default
        if (!\array_key_exists('storage_mapper', $doctrine) || !\is_string($doctrine['storage_mapper'])) {
            $doctrine['storage_mapper'] = null;
        }

        $v['doctrine'] = $doctrine;

        return $v;
    }

    /**
     * @param array{schedule: ?string, default_max_age: ?string} $config
     */
    private function loadScheduledRetention(ServicesConfigurator $services, array $config): void
    {
        if (null === $config['schedule']) {
            return;
        }

        $services->set(PruneAuditLogsHandler::class)
            ->args([
                new Reference(DoctrineProvider::class),
                $config['default_max_age'],
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ])
            ->tag('messenger.message_handler')
            // with MonologBundle, the scheduled pruning logs go to the "auditor" channel, like the provider's
            ->tag('monolog.logger', ['channel' => 'auditor'])
        ;

        $services->set(RetentionSchedule::class)
            ->args([$config['schedule']])
            ->tag('scheduler.schedule_provider', ['name' => RetentionSchedule::NAME])
        ;
    }

    /**
     * Normalizes the "providers.doctrine.retention" block (scheduled pruning, disabled by default).
     *
     * @return array{schedule: ?string, default_max_age: ?string}
     */
    private function normalizeScheduledRetentionConfig(mixed $retention): array
    {
        $normalized = ['schedule' => null, 'default_max_age' => null];
        if (null === $retention) {
            return $normalized;
        }

        if (!\is_array($retention)) {
            throw new \InvalidArgumentException('The "retention" option of the doctrine provider must be an array with "schedule" and/or "default_max_age" keys.');
        }

        $unknownKeys = array_diff(array_keys($retention), array_keys($normalized));
        if ([] !== $unknownKeys) {
            throw new \InvalidArgumentException(\sprintf('Unknown "retention" option(s) "%s" for the doctrine provider; allowed options are "schedule", "default_max_age".', implode('", "', $unknownKeys)));
        }

        $schedule = $retention['schedule'] ?? null;
        if (null !== $schedule) {
            if (!\is_string($schedule) || '' === mb_trim($schedule)) {
                throw new \InvalidArgumentException('The "retention.schedule" option of the doctrine provider must be a cron expression (e.g. "0 3 * * *").');
            }

            if (!class_exists(RecurringMessage::class) || !class_exists(CronExpression::class)) {
                throw new \LogicException('Scheduled audit logs pruning requires the Symfony Scheduler component and a cron expression parser: run "composer require symfony/scheduler dragonmantank/cron-expression".');
            }

            if (!CronExpression::isValidExpression($schedule)) {
                throw new \InvalidArgumentException(\sprintf('The "retention.schedule" option of the doctrine provider must be a valid cron expression, "%s" given.', $schedule));
            }

            $normalized['schedule'] = $schedule;
        }

        $defaultMaxAge = $retention['default_max_age'] ?? null;
        if (null !== $defaultMaxAge) {
            if (!\is_string($defaultMaxAge)) {
                throw new \InvalidArgumentException(\sprintf('The "retention.default_max_age" option of the doctrine provider must be an ISO 8601 duration string (e.g. P1Y), %s given.', get_debug_type($defaultMaxAge)));
            }

            try {
                new RetentionPolicy($defaultMaxAge);
            } catch (InvalidArgumentException $exception) {
                throw new \InvalidArgumentException('Invalid "retention.default_max_age" option for the doctrine provider: '.$exception->getMessage(), 0, $exception);
            }

            $normalized['default_max_age'] = $defaultMaxAge;
        }

        return $normalized;
    }

    /**
     * Checks the "ignored_columns" and "audited_columns" options of an entity: lists of property names, or null
     * (no property ignored, respectively every property audited).
     *
     * @param array<string, mixed> $options
     */
    private function validateColumnsConfig(string $entity, array $options): void
    {
        foreach (['ignored_columns', 'audited_columns'] as $option) {
            if (null === ($options[$option] ?? null)) {
                continue;
            }

            $columns = $options[$option];
            if (!\is_array($columns) || !array_is_list($columns) || array_any($columns, static fn (mixed $column): bool => !\is_string($column))) {
                throw new \InvalidArgumentException(\sprintf('The "%s" option of entity "%s" must be a list of property names.', $option, $entity));
            }
        }
    }

    /**
     * Checks the "roots" option of an entity: a list of audit root paths, validated like #[AuditRoot].
     *
     * @param array<string, mixed> $options
     */
    private function validateRootsConfig(string $entity, array $options): void
    {
        if (null === ($options['roots'] ?? null)) {
            return;
        }

        $roots = $options['roots'];
        if (!\is_array($roots) || !array_is_list($roots)) {
            throw new \InvalidArgumentException(\sprintf('The "roots" option of entity "%s" must be a list of property paths.', $entity));
        }

        foreach ($roots as $root) {
            if (!\is_string($root)) {
                throw new \InvalidArgumentException(\sprintf('The "roots" option of entity "%s" must be a list of property paths.', $entity));
            }

            try {
                new AuditRoot($root);
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException(\sprintf('The "roots" option of entity "%s" is invalid: %s', $entity, $exception->getMessage()), 0, $exception);
            }
        }
    }

    /**
     * Turns the optional "retention" block of an entity into the "max_age" / "max_entries" options of the
     * Doctrine provider, validated with the same rules as #[Auditable] (an invalid value fails at container build).
     *
     * Only the keys given in the "retention" block are set: they override the #[Auditable] values key by key.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function normalizeRetentionConfig(string $entity, array $options): array
    {
        if (!\array_key_exists('retention', $options)) {
            return $options;
        }

        $retention = $options['retention'];
        unset($options['retention']);

        if (null === $retention) {
            return $options;
        }

        if (!\is_array($retention)) {
            throw new \InvalidArgumentException(\sprintf('The "retention" option of entity "%s" must be an array with "max_age" and/or "max_entries" keys.', $entity));
        }

        $unknownKeys = array_diff(array_keys($retention), self::RETENTION_KEYS);
        if ([] !== $unknownKeys) {
            throw new \InvalidArgumentException(\sprintf('Unknown "retention" option(s) "%s" for entity "%s"; allowed options are "%s".', implode('", "', $unknownKeys), $entity, implode('", "', self::RETENTION_KEYS)));
        }

        $maxAge = $retention['max_age'] ?? null;
        $maxEntries = $retention['max_entries'] ?? null;
        if (null !== $maxAge && !\is_string($maxAge)) {
            throw new \InvalidArgumentException(\sprintf('The "retention.max_age" option of entity "%s" must be an ISO 8601 duration string (e.g. P1Y), %s given.', $entity, get_debug_type($maxAge)));
        }

        if (null !== $maxEntries && !\is_int($maxEntries)) {
            throw new \InvalidArgumentException(\sprintf('The "retention.max_entries" option of entity "%s" must be an integer, %s given.', $entity, get_debug_type($maxEntries)));
        }

        try {
            new RetentionPolicy($maxAge, $maxEntries);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new \InvalidArgumentException(\sprintf('Invalid "retention" option for entity "%s": %s', $entity, $invalidArgumentException->getMessage()), 0, $invalidArgumentException);
        }

        if (\array_key_exists('max_age', $retention)) {
            $options['max_age'] = $maxAge;
        }

        if (\array_key_exists('max_entries', $retention)) {
            $options['max_entries'] = $maxEntries;
        }

        return $options;
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
