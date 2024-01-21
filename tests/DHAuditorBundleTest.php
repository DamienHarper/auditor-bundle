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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @internal
 *
 * @small
 */
final class DHAuditorBundleTest extends KernelTestCase
{
    public function testInitBundle(): void
    {
        // Boot the kernel with a config closure, the handleOptions call in createKernel is important for that to work
        $kernel = self::bootKernel(['config' => static function (TestKernel $kernel) {
            // Add some other bundles we depend on
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);

            // Add some configuration
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
            if (Kernel::MAJOR_VERSION < 6) {
                $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/sf4_5/security.yaml');
            } else {
                $kernel->addTestConfig(__DIR__.'/Fixtures/Resources/config/sf6_7/security.yaml');
            }
        }]);

        // Get the container
        $container = self::getContainer();

        self::assertTrue($container->has(AuditorConfiguration::class));
        self::assertInstanceOf(AuditorConfiguration::class, $container->get(AuditorConfiguration::class));

        self::assertTrue($container->has(Auditor::class));
        self::assertInstanceOf(Auditor::class, $container->get(Auditor::class));

        self::assertTrue($container->has(DoctrineProviderConfiguration::class));
        self::assertInstanceOf(DoctrineProviderConfiguration::class, $container->get(DoctrineProviderConfiguration::class));

        self::assertTrue($container->has(DoctrineProvider::class));
        self::assertInstanceOf(DoctrineProvider::class, $container->get(DoctrineProvider::class));

        self::assertTrue($container->has('dh_auditor.provider.doctrine'));
        self::assertInstanceOf(DoctrineProvider::class, $container->get('dh_auditor.provider.doctrine'));

        self::assertTrue($container->has(Reader::class));
        self::assertInstanceOf(Reader::class, $container->get(Reader::class));

        self::assertTrue($container->has(TableSchemaListener::class));
        self::assertInstanceOf(TableSchemaListener::class, $container->get(TableSchemaListener::class));

        self::assertTrue($container->has(CreateSchemaListener::class));
        self::assertInstanceOf(CreateSchemaListener::class, $container->get(CreateSchemaListener::class));

        self::assertTrue($container->has(ViewerController::class));
        self::assertInstanceOf(ViewerController::class, $container->get(ViewerController::class));

        self::assertTrue($container->has(ConsoleEventSubscriber::class));
        self::assertInstanceOf(ConsoleEventSubscriber::class, $container->get(ConsoleEventSubscriber::class));
    }

    protected function getBundleClass(): string
    {
        return DHAuditorBundle::class;
    }

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

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
