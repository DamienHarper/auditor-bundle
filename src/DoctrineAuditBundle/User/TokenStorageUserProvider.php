<?php

namespace DH\DoctrineAuditBundle\User;

use Symfony\Component\Security\Core\Role\SwitchUserRole;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface as BaseUserInterface;

class TokenStorageUserProvider implements UserProviderInterface
{
    private $security;

    public function __construct(Security $security)
    {
        $this->security = $security;
    }

    public function getUser(): ?UserInterface
    {
        $user = null;

        try {
            $token = $this->security->getToken();
        } catch (\Exception $e) {
            $token = null;
        }

        if (null !== $token) {
            $tokenUser = $token->getUser();
            if ($tokenUser instanceof BaseUserInterface) {
                $impersonation = '';
                if ($this->security->isGranted('ROLE_PREVIOUS_ADMIN')) {
                    $impersonatorUser = null;
                    foreach ($this->security->getToken()->getRoles() as $role) {
                        if ($role instanceof SwitchUserRole) {
                            $impersonatorUser = $role->getSource()->getUser();

                            break;
                        }
                    }
                    if ($impersonatorUser) {
                        $impersonation = ' [impersonator '.$impersonatorUser->getUsername().':'.$impersonatorUser->getId().']';
                    }
                }
                $user = new User($tokenUser->getId(), $tokenUser->getUsername().$impersonation);
            }
        }

        return $user;
    }
}
