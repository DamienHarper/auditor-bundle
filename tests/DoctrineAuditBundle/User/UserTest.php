<?php

namespace DH\DoctrineAuditBundle\Tests\User;

use DH\DoctrineAuditBundle\User\User;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class UserTest extends TestCase
{
    public function testGetId(): void
    {
        $user = new User('1', 'john.doe');

        self::assertSame('1', $user->getId());
    }

    public function testGetUsername(): void
    {
        $user = new User('1', 'john.doe');

        self::assertSame('john.doe', $user->getUsername());
    }

    public function testGetIdOnEmptyUser(): void
    {
        $user = new User();

        self::assertNull($user->getId());
    }

    public function testGetUsernameOnEmptyUser(): void
    {
        $user = new User();

        self::assertNull($user->getUsername());
    }
}
