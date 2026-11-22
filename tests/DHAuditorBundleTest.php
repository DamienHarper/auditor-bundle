<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests;

use DH\Auditor\Auditor;
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
use DH\AuditorBundle\Tests\Fixtures\Provider\StubProviderA;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
}
