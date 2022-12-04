<?php

declare(strict_types=1);

namespace DH\AuditorBundle\User;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface as AuditorUserInterface;
use DH\Auditor\User\UserProviderInterface;
use Exception;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class UserProvider implements UserProviderInterface
{
    private Security $security;

    private Configuration $configuration;

    public function __construct(Security $security, Configuration $configuration)
    {
        $this->security = $security;
        $this->configuration = $configuration;
    }

    public function __invoke(): ?AuditorUserInterface
    {
        $identifier = null;
        $username = null;
        $tokenUser = null;

        $token = $this->getToken();
        if (null !== $token) {
            $tokenUser = $token->getUser();
        }

        $impersonatorUser = $this->getImpersonatorUser();

        if ($tokenUser instanceof UserInterface) {
            if (method_exists($tokenUser, 'getId')) {
                $identifier = $tokenUser->getId();
            }

            $username = '';
            if (method_exists($tokenUser, 'getUserIdentifier')) {
                $username = $tokenUser->getUserIdentifier();
            } elseif (method_exists($tokenUser, 'getUsername')) {
                $username = $tokenUser->getUsername();
            }
        }

        if ($impersonatorUser instanceof UserInterface) {
            $impersonatorUsername = '';
            if (method_exists($impersonatorUser, 'getUserIdentifier')) {
                $impersonatorUsername = $impersonatorUser->getUserIdentifier();
            } elseif (method_exists($impersonatorUser, 'getUsername')) {
                $impersonatorUsername = $impersonatorUser->getUsername();
            }

            $username .= '[impersonator '.$impersonatorUsername.']';
        }

        if (null === $identifier && null === $username) {
            return null;
        }

        return new User((string) $identifier, $username);
    }

    private function getToken(): ?TokenInterface
    {
        try {
            $token = $this->security->getToken();
        } catch (Exception) {
            $token = null;
        }

        if (!$token instanceof TokenInterface) {
            return null;
        }

        return $token;
    }

    private function getImpersonatorUser(): ?UserInterface
    {
        $token = $this->security->getToken();

        if ($token instanceof SwitchUserToken) {
            return $token->getOriginalToken()->getUser();
        }

        return null;
    }
}
