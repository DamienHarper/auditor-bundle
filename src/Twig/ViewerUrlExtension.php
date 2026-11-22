<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\AuditorBundle\Tests\Twig\ViewerUrlExtensionTest;
use DH\AuditorBundle\Viewer\AuditViewerUrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig functions linking an application's templates to the audit viewer. They return null
 * when the viewer cannot show the page (see AuditViewerUrlGenerator).
 *
 * @see ViewerUrlExtensionTest
 */
final readonly class ViewerUrlExtension
{
    public function __construct(
        private AuditViewerUrlGenerator $urlGenerator,
    ) {}

    /**
     * {{ dh_auditor_entity_url(post) }}, {{ dh_auditor_entity_url('App\\Entity\\Post', 42, {type: 'update'}) }}.
     *
     * @param class-string|object                                   $entity
     * @param array<string, null|bool|float|int|string|\Stringable> $filters
     */
    #[AsTwigFunction('dh_auditor_entity_url')]
    public function entityUrl(object|string $entity, int|string|\Stringable|null $id = null, array $filters = [], bool $absolute = false): ?string
    {
        return $this->urlGenerator->generateEntityUrl($entity, $id, $filters, $this->referenceType($absolute));
    }

    #[AsTwigFunction('dh_auditor_transaction_url')]
    public function transactionUrl(string $transactionId, bool $absolute = false): ?string
    {
        return $this->urlGenerator->generateTransactionUrl($transactionId, $this->referenceType($absolute));
    }

    #[AsTwigFunction('dh_auditor_user_url')]
    public function userUrl(int|string $userId, bool $absolute = false): ?string
    {
        return $this->urlGenerator->generateUserUrl($userId, $this->referenceType($absolute));
    }

    private function referenceType(bool $absolute): int
    {
        return $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH;
    }
}
