<?php

namespace DH\DoctrineAuditBundle\Tests\Twig\Extension;

use DH\DoctrineAuditBundle\Tests\BaseTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Author;
use DH\DoctrineAuditBundle\Twig\Extension\TwigExtension;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditManager
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Twig\Extension\TwigExtension
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 */
class TwigExtensionTest extends BaseTest
{
    /**
     * @var ContainerBuilder
     */
    protected $container;

    /**
     * @var string
     */
    protected $fixturesPath = __DIR__.'/../../Fixtures';

    public function setUp(): void
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

    public function testGetFunctions(): void
    {
        $extension = new TwigExtension($this->container->get('doctrine'));
        $functions = $extension->getFunctions();

        $this->assertNotEmpty($functions, 'extension has at least 1 function.');

        foreach ($functions as $function) {
            $this->assertInstanceOf('Twig_SimpleFunction', $function, 'function instanceof Twig_SimpleFunction');
        }
    }

    public function testFindUser(): void
    {
        $extension = new TwigExtension($this->container->get('doctrine'));

        $this->assertNull($extension->findUser(1, Author::class));

        $author = new Author();
        $author
            ->setFullname('Dark Vador')
            ->setEmail('dark.vador@gmail.com')
        ;

        $em = $this->container->get('entity_manager');
        $em->persist($author);
        $em->flush();

        $this->assertNull($extension->findUser(null, Author::class));
        $this->assertNotNull($extension->findUser(1, Author::class));
        $this->assertInstanceOf(Author::class, $extension->findUser(1, Author::class));
    }

    public function testGetClass(): void
    {
        $extension = new TwigExtension($this->container->get('doctrine'));

        $this->assertSame(Author::class, $extension->getClass(new Author()), 'TwigExtension::getClass() is ok.');
    }

    public function testGetTablename(): void
    {
        $extension = new TwigExtension($this->container->get('doctrine'));

        $this->assertSame('author', $extension->getTablename(new Author()), 'TwigExtension::getTablename() is ok.');
    }

    public function testGetName(): void
    {
        $extension = new TwigExtension($this->container->get('doctrine'));

        $this->assertSame('twig_extensions', $extension->getName(), 'TwigExtension::getName() is ok.');
    }
}
