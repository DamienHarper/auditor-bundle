<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Event\TableSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\DHAuditorBundle;
use DH\AuditorBundle\Event\ConsoleEventSubscriber;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
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
