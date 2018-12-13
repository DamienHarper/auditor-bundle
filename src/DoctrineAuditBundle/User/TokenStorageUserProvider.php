<?php

namespace DH\DoctrineAuditBundle\User;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface as BaseUserInterface;

class TokenStorageUserProvider implements UserProviderInterface
{
    private $tokenStorage;

    public function __construct(TokenStorageInterface $tokenStorage)
    {
        $this->tokenStorage = $tokenStorage;
    }

    public function getUser(): ?UserInterface
    {
        $user = null;
        $token = $this->tokenStorage->getToken();

        if (null !== $token) {
            $tokenUser = $token->getUser();
            if ($tokenUser instanceof BaseUserInterface) {
                $user = new User($tokenUser->getId(), $tokenUser->getUsername());
            }
        }

        return $user;
    }
}
