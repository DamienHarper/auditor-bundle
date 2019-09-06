<?php

namespace DH\DoctrineAuditBundle\Tests\User;

use DH\DoctrineAuditBundle\User\User;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DH\DoctrineAuditBundle\User\User
 */
class UserTest extends TestCase
{
    public function testGetId(): void
    {
        $user = new User('1', 'john.doe');

        $this->assertSame('1', $user->getId());
    }

    public function testGetUsername(): void
    {
        $user = new User('1', 'john.doe');

        $this->assertSame('john.doe', $user->getUsername());
    }

    public function testGetIdOnEmptyUser(): void
    {
        $user = new User();

        $this->assertNull($user->getId());
    }

    public function testGetUsernameOnEmptyUser(): void
    {
        $user = new User();

        $this->assertNull($user->getUsername());
    }
}
