<?php

namespace DH\DoctrineAuditBundle\Tests\User;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\AuditReader;
use DH\DoctrineAuditBundle\Command\CleanAuditLogsCommand;
use DH\DoctrineAuditBundle\DependencyInjection\DHDoctrineAuditExtension;
use DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber;
use DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener;
use DH\DoctrineAuditBundle\Twig\Extension\TwigExtension;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use DH\DoctrineAuditBundle\User\User;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DH\DoctrineAuditBundle\User\User
 */
class UserTest extends TestCase
{
    public function testGetId()
    {
        $user = new User(1, 'john.doe');

        $this->assertSame(1, $user->getId());
    }

    public function testGetUsername()
    {
        $user = new User(1, 'john.doe');

        $this->assertSame('john.doe', $user->getUsername());
    }

    public function testGetIdOnEmptyUser()
    {
        $user = new User();

        $this->assertNull($user->getId());
    }

    public function testGetUsernameOnEmptyUser()
    {
        $user = new User();

        $this->assertNull($user->getUsername());
    }
}
