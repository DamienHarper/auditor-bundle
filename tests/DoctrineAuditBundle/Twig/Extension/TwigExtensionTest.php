<?php

namespace DH\DoctrineAuditBundle\Tests\Twig\Extension;

use DH\DoctrineAuditBundle\Tests\BaseTest;
use DH\DoctrineAuditBundle\Twig\Extension\TwigExtension;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class TwigExtensionTest extends BaseTest
{
    /**
     * @var ContainerBuilder
     */
    protected $container;

    /**
     * @var string
     */
    protected $fixturesPath = [
        __DIR__.'/../../../../src/DoctrineAuditBundle/Annotation',
        __DIR__.'/../../Fixtures',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new ContainerBuilder();

        $em = $this->getEntityManager();

        $this->container->set('entity_manager', $em);
        $this->container->setAlias('doctrine.orm.default_entity_manager', 'entity_manager');

        $registry = new Registry(
            $this->container,
            [],
            ['default' => 'entity_manager'],
            'default',
            'default'
        );

        $this->container->set('doctrine', $registry);
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
}
