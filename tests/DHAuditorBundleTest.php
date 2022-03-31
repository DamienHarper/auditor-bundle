<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\DHAuditorBundle;
use DH\AuditorBundle\Event\ConsoleEventSubscriber;
use DH\AuditorBundle\Twig\Extension\TwigExtension;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\BaseBundleTestCase;
use Nyholm\BundleTest\CompilerPass\PublicServicePass;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @internal
 *
 * @small
 */
final class DHAuditorBundleTest extends BaseBundleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Make services public
        $this->addCompilerPass(new PublicServicePass('#^(DH\\\\Auditor(Bundle)?\\\\|dh_auditor\.).*$#'));
    }

    public function testInitBundle(): void
    {
        $kernel = $this->createKernel();

        $kernel->addConfigFile(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
        $kernel->addConfigFile(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
        if (6 === Kernel::MAJOR_VERSION) {
            $kernel->addConfigFile(__DIR__.'/Fixtures/Resources/config/sf6/security.yaml');
        } else {
            $kernel->addConfigFile(__DIR__.'/Fixtures/Resources/config/sf4_5/security.yaml');
        }

        $kernel->addBundle(DoctrineBundle::class);
        $kernel->addBundle(SecurityBundle::class);
        $kernel->addBundle(TwigBundle::class);

        $this->bootKernel();

        $container = $this->getContainer();

        self::assertTrue($container->has(\DH\Auditor\Configuration::class));
        self::assertInstanceOf(AuditorConfiguration::class, $container->get(\DH\Auditor\Configuration::class));

        self::assertTrue($container->has(\DH\Auditor\Auditor::class));
        self::assertInstanceOf(Auditor::class, $container->get(\DH\Auditor\Auditor::class));

        self::assertTrue($container->has(\DH\Auditor\Provider\Doctrine\Configuration::class));
        self::assertInstanceOf(DoctrineProviderConfiguration::class, $container->get(\DH\Auditor\Provider\Doctrine\Configuration::class));

        self::assertTrue($container->has(\DH\Auditor\Provider\Doctrine\DoctrineProvider::class));
        self::assertInstanceOf(DoctrineProvider::class, $container->get(\DH\Auditor\Provider\Doctrine\DoctrineProvider::class));

        self::assertTrue($container->has('dh_auditor.provider.doctrine'));
        self::assertInstanceOf(DoctrineProvider::class, $container->get('dh_auditor.provider.doctrine'));

        self::assertTrue($container->has(\DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader::class));
        self::assertInstanceOf(Reader::class, $container->get(\DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader::class));

        self::assertTrue($container->has(\DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener::class));
        self::assertInstanceOf(CreateSchemaListener::class, $container->get(\DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener::class));

        self::assertTrue($container->has(\DH\AuditorBundle\Controller\ViewerController::class));
        self::assertInstanceOf(ViewerController::class, $container->get(\DH\AuditorBundle\Controller\ViewerController::class));

        self::assertTrue($container->has(\DH\AuditorBundle\Twig\Extension\TwigExtension::class));
        self::assertInstanceOf(TwigExtension::class, $container->get(\DH\AuditorBundle\Twig\Extension\TwigExtension::class));

        self::assertTrue($container->has(\DH\AuditorBundle\Event\ConsoleEventSubscriber::class));
        self::assertInstanceOf(ConsoleEventSubscriber::class, $container->get(\DH\AuditorBundle\Event\ConsoleEventSubscriber::class));
    }

    protected function getBundleClass()
    {
        return DHAuditorBundle::class;
    }
}
