<?php

namespace DH\DoctrineAuditBundle\Tests\Manager;

use DateTime;
use DH\DoctrineAuditBundle\Annotation\AnnotationLoader;
use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Manager\TransactionManager;
use DH\DoctrineAuditBundle\Reader\Reader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Tag;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\CoreCase;
use DH\DoctrineAuditBundle\Tests\Fixtures\Issue40\DieselCase;
use DH\DoctrineAuditBundle\Tests\ReflectionTrait;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use DH\DoctrineAuditBundle\User\User;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @internal
 */
final class TransactionManagerTest extends CoreTest
{
    public function testGetConfiguration(): void
    {
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);

        self::assertInstanceOf(Configuration::class, $manager->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
    }

    protected function setupEntities(): void
    {
    }
}
