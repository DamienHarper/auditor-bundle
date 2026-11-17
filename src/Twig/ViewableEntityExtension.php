<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\Auditor\Attribute\Security;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\AuditorBundle\Tests\Twig\ViewableEntityExtensionTest;
use Twig\Attribute\AsTwigFunction;

/**
 * @see ViewableEntityExtensionTest
 */
final readonly class ViewableEntityExtension
{
    public function __construct(
        private DoctrineProvider $provider,
    ) {}

    /**
     * Tells whether the current user can open the audit stream of the given entity class,
     * applying the same rules as the entity stream page: the class must be auditable
     * (otherwise 404) and the role checker must grant the "view" scope (otherwise 403).
     */
    #[AsTwigFunction('dh_auditor_is_viewable')]
    public function isViewable(string $entityClass): bool
    {
        if (!$this->provider->isAuditable($entityClass)) {
            return false;
        }

        $roleChecker = $this->provider->getAuditor()->getConfiguration()->getRoleChecker();

        return null === $roleChecker || (bool) $roleChecker($entityClass, Security::VIEW_SCOPE);
    }
}
