<?php

namespace DH\AuditorBundle\Tests;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\DHAuditorBundle;
use DH\AuditorBundle\Twig\Extension\TwigExtension;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\BaseBundleTestCase;
use Nyholm\BundleTest\CompilerPass\PublicServicePass;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @internal
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
        if (3 === Kernel::MAJOR_VERSION) {
            self::markTestSkipped('Test skipped for Symfony <= 3.4');
        }

        $kernel = $this->createKernel();

        $kernel->addConfigFile(__DIR__.'/Fixtures/Resources/config/dh_auditor.yaml');
        $kernel->addConfigFile(__DIR__.'/Fixtures/Resources/config/doctrine.yaml');
        $kernel->addConfigFile(__DIR__.'/Fixtures/Resources/config/security.yaml');

        $kernel->addBundle(DoctrineBundle::class);
        $kernel->addBundle(SecurityBundle::class);
        $kernel->addBundle(TwigBundle::class);

        $this->bootKernel();

        $container = $this->getContainer();

        self::assertTrue($container->has('DH\Auditor\Configuration'));
        self::assertInstanceOf(AuditorConfiguration::class, $container->get('DH\Auditor\Configuration'));

        self::assertTrue($container->has('DH\Auditor\Auditor'));
        self::assertInstanceOf(Auditor::class, $container->get('DH\Auditor\Auditor'));

        self::assertTrue($container->has('DH\Auditor\Provider\Doctrine\Configuration'));
        self::assertInstanceOf(DoctrineProviderConfiguration::class, $container->get('DH\Auditor\Provider\Doctrine\Configuration'));

        self::assertTrue($container->has('DH\Auditor\Provider\Doctrine\DoctrineProvider'));
        self::assertInstanceOf(DoctrineProvider::class, $container->get('DH\Auditor\Provider\Doctrine\DoctrineProvider'));

        self::assertTrue($container->has('dh_auditor.provider.doctrine'));
        self::assertInstanceOf(DoctrineProvider::class, $container->get('dh_auditor.provider.doctrine'));

        self::assertTrue($container->has('DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader'));
        self::assertInstanceOf(Reader::class, $container->get('DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader'));

        self::assertTrue($container->has('DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener'));
        self::assertInstanceOf(CreateSchemaListener::class, $container->get('DH\Auditor\Provider\Doctrine\Persistence\Event\CreateSchemaListener'));

        self::assertTrue($container->has('DH\AuditorBundle\Controller\ViewerController'));
        self::assertInstanceOf(ViewerController::class, $container->get('DH\AuditorBundle\Controller\ViewerController'));

        self::assertTrue($container->has('DH\AuditorBundle\Twig\Extension\TwigExtension'));
        self::assertInstanceOf(TwigExtension::class, $container->get('DH\AuditorBundle\Twig\Extension\TwigExtension'));
    }

    protected function getBundleClass()
    {
        return DHAuditorBundle::class;
    }
}
