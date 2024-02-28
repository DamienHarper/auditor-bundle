<?php

declare(strict_types=1);

namespace DH\AuditorBundle\User;

use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface as AuditorUserInterface;
use DH\Auditor\User\UserProviderInterface;
use Exception;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\User\UserInterface;

class UserProvider implements UserProviderInterface
{
    private TokenStorageInterface $tokenStorage;

    public function __construct(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;
    }

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

            $username = $this->getUsername($tokenUser);
        }

        if ($impersonatorUser instanceof UserInterface) {
            $impersonatorUsername = $this->getUsername($impersonatorUser);
            $username .= '[impersonator '.$impersonatorUsername.']';
        }

        if (null === $identifier && null === $username) {
            return null;
        }

        return new User((string) $identifier, $username);
    }

    private function getUsername(UserInterface $user): string
    {
        if (method_exists($user, 'getUserIdentifier')) {
            return $user->getUserIdentifier();
        }
        if (method_exists($user, 'getUsername')) {
            return $user->getUsername();
        }

        return '';
    }

    private function getTokenUser(): ?UserInterface
    {
        try {
            $token = $this->tokenStorage->getToken();
        } catch (Exception $e) {
            $token = null;
        }

        if (null === $token) {
            return null;
        }

        $tokenUser = $token->getUser();
        if ($tokenUser instanceof UserInterface) {
            return $tokenUser;
        }

        return null;
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
