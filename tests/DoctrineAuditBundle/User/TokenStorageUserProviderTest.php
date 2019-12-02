<?php

namespace DH\DoctrineAuditBundle\Tests\User;

use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\User;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Role\SwitchUserRole;
use Symfony\Component\Security\Core\Security;

/**
 * @internal
 */
final class TokenStorageUserProviderTest extends TestCase
{
    private $authorizationChecker;
    private $tokenStorage;

    protected function setUp(): void
    {
        $this->tokenStorage = new TokenStorage();
        $this->authorizationChecker = $this->getMockBuilder(AuthorizationCheckerInterface::class)->getMock();
        $this->authorizationChecker
            ->expects(self::any())
            ->method('isGranted')
            ->with('ROLE_PREVIOUS_ADMIN')
            ->willReturn(true)
        ;
    }

    public function testGetUserWhenNoUserDefined(): void
    {
        $container = new ContainerBuilder();
        $security = new Security($container);
        $token = new TokenStorageUserProvider($security);

        self::assertNull($token->getUser());
    }

    public function testGetUserWhenUserIsDefined(): void
    {
        $container = new ContainerBuilder();
        $security = new Security($container);
        $token = new TokenStorageUserProvider($security);

        $user1 = new User('1', 'john.doe');
        $user1->setRoles(['ROLE_ADMIN']);
        $token1 = new UsernamePasswordToken($user1, '12345', 'provider', $user1->getRoles());

        $user2 = new User('2', 'dark.vador');

        if (class_exists('\Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken')) {
            $token2 = new SwitchUserToken($user2, '12345', 'provider', $user2->getRoles(), $token1);
        } else {
            $user2->setRoles(['ROLE_USER', 'ROLE_PREVIOUS_ADMIN', new SwitchUserRole('ROLE_ADMIN', $token1)]);
            $token2 = new UsernamePasswordToken($user2, '12345', 'provider', $user2->getRoles());
        }

        $this->tokenStorage->setToken($token2);
        $container->set('security.token_storage', $this->tokenStorage);
        $container->set('security.authorization_checker', $this->authorizationChecker);

        self::assertSame('2', $token->getUser()->getId());
        self::assertSame('dark.vador [impersonator john.doe:1]', $token->getUser()->getUsername());
    }
}
