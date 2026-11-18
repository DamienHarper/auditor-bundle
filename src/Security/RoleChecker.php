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
    public function __construct(private readonly AuthorizationCheckerInterface $authorizationChecker, private readonly DoctrineProvider $provider) {}

    public function __invoke(string $entity, string $scope): bool
    {
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

        $userProvider = $this->provider->getAuditor()->getConfiguration()->getUserProvider();
        $user = null !== $userProvider ? $userProvider() : null;

        if (!$user instanceof UserInterface) {
            // Roles are required for this scope but no user is identified: deny access
            return false;
        }

        // roles are defined for the given scope
        foreach ($roles[$scope] as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                // role granted => access granted
                return true;
            }
        }

        // access denied
        return false;
    }
}
