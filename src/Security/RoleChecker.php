<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Security;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Security\RoleCheckerInterface;
use DH\Auditor\User\UserInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class RoleChecker implements RoleCheckerInterface
{
    private AuthorizationCheckerInterface $authorizationChecker;

    private DoctrineProvider $provider;

    public function __construct(AuthorizationCheckerInterface $authorizationChecker, DoctrineProvider $doctrineProvider)
    {
        $this->authorizationChecker = $authorizationChecker;
        $this->provider = $doctrineProvider;
    }

    public function __invoke(string $entity, string $scope): bool
    {
        $userProvider = $this->provider->getAuditor()->getConfiguration()->getUserProvider();
        $user = null !== $userProvider ? $userProvider() : null;
        $authorizationChecker = null !== $userProvider ? $this->authorizationChecker : null;

        if (!($user instanceof UserInterface) || !($authorizationChecker instanceof AuthorizationCheckerInterface)) {
            // If no security defined or no user identified, consider access granted
            return true;
        }

        \assert($this->provider->getConfiguration() instanceof Configuration);
        $entities = $this->provider->getConfiguration()->getEntities();
        $roles = $entities[$entity]['roles'] ?? null;

        if (null === $roles) {
            // If no roles are configured, consider access granted
            return true;
        }

        if (!\array_key_exists($scope, $roles)) {
            // If no roles for the given scope are configured, consider access granted
            return true;
        }

        // roles are defined for the give scope
        foreach ($roles[$scope] as $role) {
            if ($authorizationChecker->isGranted($role)) {
                // role granted => access granted
                return true;
            }
        }

        // access denied
        return false;
    }
}
