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
        $userProvider = $this->provider->getAuditor()->getConfiguration()->getUserProvider();
        $user = null !== $userProvider ? $userProvider() : null;
        $authorizationChecker = null !== $userProvider ? $this->authorizationChecker : null;

        if (!($user instanceof UserInterface) || !($authorizationChecker instanceof AuthorizationCheckerInterface)) {
            // If no security defined or no user identified, consider access granted
            return true;
        }

        \assert($this->provider->getConfiguration() instanceof Configuration);
        $entities = $this->provider->getConfiguration()->getEntities();

        /** @var null|array<string, mixed> $entityConfig */
        $entityConfig = $entities[$entity] ?? null;

        /** @var null|array<string, list<string>> $roles */
        $roles = \is_array($entityConfig) ? ($entityConfig['roles'] ?? null) : null;

        if (null === $roles) {
            // If no roles are configured, consider access granted
            return true;
        }

        if (!\array_key_exists($scope, $roles)) {
            // If no roles for the given scope are configured, consider access granted
            return true;
        }

        return array_any($roles[$scope], static fn ($role): bool => $authorizationChecker->isGranted($role));
    }
}
