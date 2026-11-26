<?php

declare(strict_types=1);

namespace DH\AuditorBundle\User;

use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface as AuditorUserInterface;
use DH\Auditor\User\UserProviderInterface;
use DH\AuditorBundle\Tests\User\UserProviderTest;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Blames the user of the security token, with its class; when that user is impersonated (switch user), the user of
 * the original token is its impersonator.
 *
 * @see UserProviderTest
 */
final readonly class UserProvider implements UserProviderInterface
{
    public function __construct(private TokenStorageInterface $tokenStorage) {}

    public function __invoke(): ?AuditorUserInterface
    {
        $token = $this->getToken();
        $tokenUser = $token?->getUser();

        if (!$tokenUser instanceof UserInterface) {
            return null;
        }

        $impersonatorUser = $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUser() : null;
        $impersonator = $impersonatorUser instanceof UserInterface
            ? new User($this->getId($impersonatorUser), $impersonatorUser->getUserIdentifier(), DoctrineHelper::getRealClassName($impersonatorUser))
            : null;

        // a user without getId() is blamed with an empty identifier
        return new User($this->getId($tokenUser) ?? '', $tokenUser->getUserIdentifier(), DoctrineHelper::getRealClassName($tokenUser), $impersonator);
    }

    private function getToken(): ?TokenInterface
    {
        try {
            return $this->tokenStorage->getToken();
        } catch (\Exception) {
            return null;
        }
    }

    private function getId(UserInterface $user): ?string
    {
        if (!method_exists($user, 'getId')) {
            return null;
        }

        /** @var null|int|string $id */
        $id = $user->getId();

        return null === $id ? null : (string) $id;
    }
}
