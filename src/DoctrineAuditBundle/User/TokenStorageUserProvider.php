<?php

namespace DH\DoctrineAuditBundle\User;

use Exception;
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

    /**
     * @return null|UserInterface
     */
    public function getUser(): ?UserInterface
    {
        try {
            $token = $this->security->getToken();
        } catch (Exception $e) {
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
            // Symfony > 4.3
            if ($token instanceof SwitchUserToken) {
                $impersonatorUser = $token->getOriginalToken()->getUser();
            } else {
                $impersonatorUser = $this->getImpersonatorUser();
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

    /**
     * @return null|Security
     */
    public function getSecurity(): ?Security
    {
        return $this->security;
    }

    private function getImpersonatorUser()
    {
        $token = $this->security->getToken();

        // Symfony 5
        if (class_exists('\Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken')) {
            if ($token instanceof SwitchUserToken) {
                return $token->getOriginalToken()->getUser();
            }
        }

        // Pre Symfony 5
        $roles = [];
        if (null !== $token) {
            $roles = method_exists($token, 'getRoleNames') ? $token->getRoleNames() : $token->getRoles();
        }

        foreach ($roles as $role) {
            if ($role instanceof SwitchUserRole) {
                return $role->getSource()->getUser();
            }
        }

        return null;
    }
}
