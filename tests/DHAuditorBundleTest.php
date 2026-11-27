<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests;

use DH\Auditor\Auditor;
use DH\Auditor\Blame\BlameContextStack;
use DH\Auditor\Configuration;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\EventSubscriber\AuditEventSubscriber;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Command\SnapshotCommand;
use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Event\TableSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\DHAuditorBundle;
use DH\AuditorBundle\Event\ConsoleEventSubscriber;
use DH\AuditorBundle\Messenger\BlameContextMiddleware;
use DH\AuditorBundle\Security\SecurityProvider;
use DH\AuditorBundle\Tests\Fixtures\Provider\StubProviderA;
use DH\AuditorBundle\Tests\Fixtures\User\CustomUserProvider;
use DH\AuditorBundle\User\ConsoleUserProvider;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @internal
 */
#[Small]
final class DHAuditorBundleTest extends KernelTestCase
{
    public function testInitBundle(): void
    {
        // Boot the kernel with a config closure, the handleOptions call in createKernel is important for that to work
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);

            // Add some other bundles we depend on
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);

            // Add some configuration
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
        }]);

        // Get the container
        $container = self::getContainer();

        $this->assertTrue($container->has(AuditorConfiguration::class));
        $this->assertInstanceOf(AuditorConfiguration::class, $container->get(AuditorConfiguration::class));

        $this->assertTrue($container->has(Auditor::class));
        $this->assertInstanceOf(Auditor::class, $container->get(Auditor::class));

        $this->assertTrue($container->has(DoctrineProviderConfiguration::class));
        $this->assertInstanceOf(DoctrineProviderConfiguration::class, $container->get(DoctrineProviderConfiguration::class));

        $this->assertTrue($container->has(DoctrineProvider::class));
        $this->assertInstanceOf(DoctrineProvider::class, $container->get(DoctrineProvider::class));

        $this->assertTrue($container->has('dh_auditor.provider.doctrine'));
        $this->assertInstanceOf(DoctrineProvider::class, $container->get('dh_auditor.provider.doctrine'));

        $this->assertTrue($container->has(Reader::class));
        $this->assertInstanceOf(Reader::class, $container->get(Reader::class));

        $this->assertTrue($container->has(TableSchemaListener::class));
        $this->assertInstanceOf(TableSchemaListener::class, $container->get(TableSchemaListener::class));

        $this->assertTrue($container->has(CreateSchemaListener::class));
        $this->assertInstanceOf(CreateSchemaListener::class, $container->get(CreateSchemaListener::class));

        $this->assertTrue($container->has(ViewerController::class));
        $this->assertInstanceOf(ViewerController::class, $container->get(ViewerController::class));

        $this->assertTrue($container->has(ConsoleEventSubscriber::class));
        $this->assertInstanceOf(ConsoleEventSubscriber::class, $container->get(ConsoleEventSubscriber::class));

        $kernel = self::$kernel;
        $this->assertInstanceOf(KernelInterface::class, $kernel);
        $command = new Application($kernel)->find('audit:snapshot');
        $this->assertInstanceOf(SnapshotCommand::class, $command instanceof LazyCommand ? $command->getCommand() : $command);
    }

    public function testBlameContextServices(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');

            // nothing uses these services in the test application: keep them in the container
            $kernel->addTestCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    $container->getDefinition(BlameContextStack::class)->setPublic(true);
                    $container->getDefinition(BlameContextMiddleware::class)->setPublic(true);
                    $container->getAlias('dh_auditor.blame_context_stack')->setPublic(true);
                    $container->getAlias('dh_auditor.messenger.blame_context_middleware')->setPublic(true);
                }
            });
        }]);

        $container = self::getContainer();
        $stack = $container->get(Configuration::class)->getBlameContextStack();

        $this->assertSame($stack, $container->get(BlameContextStack::class), 'The blame context stack service is the one of the auditor configuration.');
        $this->assertSame($stack, $container->get('dh_auditor.blame_context_stack'));
        $this->assertInstanceOf(BlameContextMiddleware::class, $container->get('dh_auditor.messenger.blame_context_middleware'));
    }

    public function testDoctrineProviderRegisteredWithAuditor(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
        }]);

        $auditor = self::getContainer()->get(Auditor::class);
        Assert::assertInstanceOf(Auditor::class, $auditor);

        $this->assertTrue($auditor->hasProvider(DoctrineProvider::class), 'DoctrineProvider must be registered with Auditor via the compiler pass');
    }

    public function testAuditEventSubscriberIsDeclaredOnceInTheContainer(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
        }]);

        $container = self::getContainer();

        // Instantiating the Auditor must not add the subscriber at runtime (deprecated on Symfony 8.2's
        // compiled dispatcher): the container declares it, so it is listed exactly once.
        $this->assertInstanceOf(Auditor::class, $container->get(Auditor::class));

        $dispatcher = $container->get('event_dispatcher');
        Assert::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $listeners = $dispatcher->getListeners(LifecycleEvent::class);
        $subscribers = array_values(array_filter(
            $listeners,
            static fn (mixed $listener): bool => \is_array($listener) && $listener[0] instanceof AuditEventSubscriber,
        ));

        $this->assertCount(1, $subscribers, 'AuditEventSubscriber must be registered exactly once');
        $this->assertSame(AuditEventSubscriber::PRIORITY, $dispatcher->getListenerPriority(LifecycleEvent::class, $subscribers[0]));
    }

    public function testCustomProviderRegisteredViaTag(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
            // Load the custom provider service definition (tagged dh_auditor.provider)
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/custom_provider.yaml');
        }]);

        $auditor = self::getContainer()->get(Auditor::class);
        Assert::assertInstanceOf(Auditor::class, $auditor);

        $this->assertTrue($auditor->hasProvider(StubProviderA::class), 'Custom provider tagged with dh_auditor.provider must be auto-registered with Auditor');
    }

    public function testConfiguredUserProviderIsRestoredAfterACommand(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/custom_user_provider.yaml');
        }]);

        $container = self::getContainer();
        $configuration = $container->get(AuditorConfiguration::class);
        Assert::assertInstanceOf(AuditorConfiguration::class, $configuration);
        $customUserProvider = $container->get('app.custom_user_provider');
        $this->assertInstanceOf(CustomUserProvider::class, $customUserProvider);
        $this->assertSame($customUserProvider, $configuration->getUserProvider(), 'The configured user provider is set on the auditor configuration.');

        $dispatcher = $container->get('event_dispatcher');
        Assert::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $command = new Command('app:test');
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), ConsoleEvents::COMMAND);
        $consoleUserProvider = $configuration->getUserProvider();
        $this->assertInstanceOf(ConsoleUserProvider::class, $consoleUserProvider, 'The console user provider is used while the command runs.');

        // a command run from another one
        $innerCommand = new Command('app:inner');
        $dispatcher->dispatch(new ConsoleCommandEvent($innerCommand, $input, $output), ConsoleEvents::COMMAND);
        $this->assertSame('app:inner', $consoleUserProvider()?->identifier);
        $dispatcher->dispatch(new ConsoleTerminateEvent($innerCommand, $input, $output, Command::SUCCESS), ConsoleEvents::TERMINATE);
        $this->assertSame($consoleUserProvider, $configuration->getUserProvider(), 'The outer command still runs: the console user provider stays.');
        $this->assertSame('app:test', $consoleUserProvider()?->identifier, 'The outer command is blamed again.');

        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, Command::SUCCESS), ConsoleEvents::TERMINATE);
        $this->assertSame($customUserProvider, $configuration->getUserProvider(), 'The configured user provider is restored once the command ends, not the default one.');
    }

    public function testBundleBootsWithNoProviders(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/no_providers.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
        }]);

        $container = self::getContainer();

        $this->assertTrue($container->has(Auditor::class), 'Auditor service must be registered even with no providers configured');
        $this->assertFalse($container->getParameter('dh_auditor.viewer_enabled'), 'dh_auditor.viewer_enabled must be false when no providers are configured');
    }

    public function testViewerEnabledParameterIsFalseByDefault(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
        }]);

        $container = self::getContainer();

        $this->assertFalse($container->getParameter('dh_auditor.viewer_enabled'), 'dh_auditor.viewer_enabled must be false when viewer is not enabled in config');
    }

    public function testCompressionIsDisabledByDefault(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
        }]);

        $configuration = self::getContainer()->get(DoctrineProviderConfiguration::class);
        Assert::assertInstanceOf(DoctrineProviderConfiguration::class, $configuration);

        $this->assertFalse($configuration->isCompressionEnabled(), '"compression" is disabled by default.');
    }

    public function testCompressionIsPassedToTheDoctrineProvider(): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/compression.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
        }]);

        $configuration = self::getContainer()->get(DoctrineProviderConfiguration::class);
        Assert::assertInstanceOf(DoctrineProviderConfiguration::class, $configuration);

        $this->assertTrue($configuration->isCompressionEnabled(), '"compression" is passed to the DoctrineProvider configuration.');
    }

    public function testTheClientIpIsKeptByDefault(): void
    {
        $this->assertSame('192.168.1.42', $this->clientIpOfTheSecurityProvider([]));
    }

    public function testAnonymizeIpIsPassedToTheSecurityProvider(): void
    {
        $this->assertSame('192.168.1.0', $this->clientIpOfTheSecurityProvider([__DIR__.'/Fixtures/Resources/config/anonymize_ip.yaml']));
    }

    #[\Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        /**
         * @var TestKernel $kernel
         */
        $kernel = parent::createKernel($options);
        $kernel->addTestBundle(DHAuditorBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }

    /**
     * Client IP that the security provider of a kernel booted with $configs gives for a request from 192.168.1.42.
     *
     * @param list<string> $configs
     */
    private function clientIpOfTheSecurityProvider(array $configs): ?string
    {
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($configs): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/security.yaml');
            foreach ($configs as $config) {
                $kernel->addTestConfig($config);
            }
        }]);

        $requestStack = self::getContainer()->get('request_stack');
        Assert::assertInstanceOf(RequestStack::class, $requestStack);
        $requestStack->push(Request::create('/', server: ['REMOTE_ADDR' => '192.168.1.42']));

        $securityProvider = self::getContainer()->get(SecurityProvider::class);
        Assert::assertInstanceOf(SecurityProvider::class, $securityProvider);

        return $securityProvider()[0];
    }
}
