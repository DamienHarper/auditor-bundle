<?php

namespace DH\DoctrineAuditBundle\User;

use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface as BaseUserInterface;
use Symfony\Component\Security\Core\Role\SwitchUserRole;

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
        $impersonation = '';

        $token = $this->security->getToken();
        if (null !== $token) {
            $tokenUser = $token->getUser();
            if ($tokenUser instanceof BaseUserInterface) {
                if ($this->security->isGranted('ROLE_PREVIOUS_ADMIN') /* && config track impersonation?? */ ) {
                    foreach ($this->security->getToken()->getRoles() as $role) {
                        if ($role instanceof SwitchUserRole) {
                            $impersonatorUser = $role->getSource()->getUser();
                            break;
                        }
                    }
                    $impersonation = ' [impersonator '.$impersonatorUser->getUsername().':'.$impersonatorUser->getId().']';
                }
                $user = new User($tokenUser->getId(), $tokenUser->getUsername().$impersonation);
            }
        }

        return $user;
    }
}
