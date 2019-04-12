<?php

namespace DH\DoctrineAuditBundle\Tests\User;

use DH\DoctrineAuditBundle\Tests\Fixtures\Core\User;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Role\SwitchUserRole;
use Symfony\Component\Security\Core\Security;

/**
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 * @covers \DH\DoctrineAuditBundle\User\User
 */
class TokenStorageUserProviderTest extends TestCase
{
    private $authorizationChecker;
    private $tokenStorage;

    public function testGetUserWhenNoUserDefined(): void
    {
        $container = new ContainerBuilder();
        $security = new Security($container);
        $token = new TokenStorageUserProvider($security);

        $this->assertNull($token->getUser());
    }

    public function testGetUserWhenUserIsDefined(): void
    {
        $container = new ContainerBuilder();
        $security = new Security($container);
        $token = new TokenStorageUserProvider($security);

        $user1 = new User(1, 'john.doe');
        $user1->setRoles(['ROLE_ADMIN']);
        $token1 = new UsernamePasswordToken($user1, '12345', 'provider', $user1->getRoles());

        $user2 = new User(2, 'dark.vador');
        $user2->setRoles(['ROLE_USER', 'ROLE_PREVIOUS_ADMIN', new SwitchUserRole('ROLE_ADMIN', $token1)]);
        $token2 = new UsernamePasswordToken($user2, '12345', 'provider', $user2->getRoles());

        $this->tokenStorage->setToken($token2);
        $container->set('security.token_storage', $this->tokenStorage);
        $container->set('security.authorization_checker', $this->authorizationChecker);

        $this->assertSame(2, $token->getUser()->getId());
        $this->assertSame('dark.vador [impersonator john.doe:1]', $token->getUser()->getUsername());
    }

    public function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
        $this->authorizationChecker = $this->getMockBuilder(AuthorizationCheckerInterface::class)->getMock();
        $this->authorizationChecker
            ->expects($this->any())
            ->method('isGranted')
            ->with('ROLE_PREVIOUS_ADMIN')
            ->willReturn(true)
        ;
    }
}
