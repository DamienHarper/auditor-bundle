<?php

namespace DH\DoctrineAuditBundle\User;

use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
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

        if (null === $token) {
            return null;
        }

        $tokenUser = $token->getUser();
        if (!($tokenUser instanceof BaseUserInterface)) {
            return null;
        }

        $impersonation = '';
        if ($this->security->isGranted('ROLE_PREVIOUS_ADMIN')) {
            $impersonatorUser = null;
            // Symfony > 4.3
            if ($token instanceof SwitchUserToken) {
                $impersonatorUser = $token->getOriginalToken()->getUser();
            } else {
                foreach ($this->security->getToken()->getRoles() as $role) {
                    if ($role instanceof SwitchUserRole) {
                        $impersonatorUser = $role->getSource()->getUser();

                        break;
                    }
                }
            }

            if (\is_object($impersonatorUser)) {
                $id = method_exists($impersonatorUser, 'getId') ? $impersonatorUser->getId() : null;
                $username = method_exists($impersonatorUser, 'getUsername') ? $impersonatorUser->getUsername() : (string) $impersonatorUser;
                $impersonation = ' [impersonator '.$username.':'.$id.']';
            }
        }
        $id = method_exists($tokenUser, 'getId') ? $tokenUser->getId() : null;

        return new User($id, $tokenUser->getUsername().$impersonation);
    }
}
