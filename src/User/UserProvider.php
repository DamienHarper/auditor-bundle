<?php

declare(strict_types=1);

namespace DH\AuditorBundle\User;

use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface as AuditorUserInterface;
use DH\Auditor\User\UserProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserProvider implements UserProviderInterface
{
    public function __construct(private readonly TokenStorageInterface $tokenStorage) {}

    public function __invoke(): ?AuditorUserInterface
    {
        $tokenUser = $this->getTokenUser();
        $impersonatorUser = $this->getImpersonatorUser();

        $identifier = null;
        $username = null;

        if ($tokenUser instanceof UserInterface) {
            if (method_exists($tokenUser, 'getId')) {
                $identifier = $tokenUser->getId();
            }
            $username = $tokenUser->getUserIdentifier();
        }

        if ($impersonatorUser instanceof UserInterface) {
            $impersonatorUsername = $impersonatorUser->getUserIdentifier();
            $username .= '[impersonator '.$impersonatorUsername.']';
        }

        if (null === $identifier && null === $username) {
            return null;
        }

        return new User((string) $identifier, $username);
    }

    private function getTokenUser(): ?UserInterface
    {
        try {
            $token = $this->tokenStorage->getToken();
        } catch (\Exception) {
            $token = null;
        }

        if (!$token instanceof TokenInterface) {
            return null;
        }

        return $token->getUser();
    }

    private function getImpersonatorUser(): ?UserInterface
    {
        $token = $this->tokenStorage->getToken();

        if ($token instanceof SwitchUserToken) {
            return $token->getOriginalToken()->getUser();
        }

        return null;
    }
}
