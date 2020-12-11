<?php

namespace DH\AuditorBundle\Tests\Twig\Extension;

use DH\AuditorBundle\DHAuditorBundle;
use DH\AuditorBundle\Twig\Extension\TwigExtension;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\BaseBundleTestCase;
use Nyholm\BundleTest\CompilerPass\PublicServicePass;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\HttpKernel\Kernel;

/**
 * @internal
 */
final class TwigExtensionTest extends BaseBundleTestCase
{
    protected $container;

    protected function setUp(): void
    {
        if (3 === Kernel::MAJOR_VERSION) {
            self::markTestSkipped('Test skipped for Symfony <= 3.4');
        }

        parent::setUp();

        // Make services public
        $this->addCompilerPass(new PublicServicePass('#^(DH\\\\Auditor(Bundle)?\\\\|dh_auditor\.).*$#'));

        $kernel = $this->createKernel();

        $kernel->addConfigFile(__DIR__.'/../../Fixtures/Resources/config/dh_auditor.yaml');
        $kernel->addConfigFile(__DIR__.'/../../Fixtures/Resources/config/doctrine.yaml');
        $kernel->addConfigFile(__DIR__.'/../../Fixtures/Resources/config/security.yaml');

        $kernel->addBundle(DoctrineBundle::class);
        $kernel->addBundle(SecurityBundle::class);

        $this->bootKernel();

        $this->container = $this->getContainer();
    }

    public function testGetFilters(): void
    {
        $extension = new TwigExtension($this->container->get('doctrine'));
        $filters = $extension->getFilters();

        self::assertNotEmpty($filters, 'extension has at least 1 filter.');

        foreach ($filters as $filter) {
            self::assertInstanceOf('Twig\TwigFilter', $filter, 'filter instanceof Twig\TwigFilter');
        }
    }

    public function testGetName(): void
    {
        $extension = new TwigExtension($this->container->get('doctrine'));

        self::assertSame('twig_extensions', $extension->getName(), 'TwigExtension::getName() is ok.');
    }

    protected function getBundleClass()
    {
        return DHAuditorBundle::class;
    }
}
