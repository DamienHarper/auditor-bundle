<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Provider\Doctrine\Auditing\Transaction\AuditTrait;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\AuditorBundle\Helper\UrlHelper;
use DH\AuditorBundle\Tests\Viewer\AuditViewerUrlGeneratorTest;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Generates links to the audit viewer, so that an application does not depend on the route names
 * and parameters of the viewer, nor on the way an entity class is encoded in its URLs.
 *
 * Every method returns null when the viewer cannot show the page: viewer disabled (its routes are
 * not registered), auditor disabled, or entity class not audited. Access rights are not checked:
 * use dh_auditor_is_viewable() (ViewableEntityExtension) to hide a link from users who cannot open it.
 *
 * @see AuditViewerUrlGeneratorTest
 */
final class AuditViewerUrlGenerator
{
    // Computes the object id exactly as it is stored in the audit table (UUIDs, enums, custom types…).
    use AuditTrait;

    public const string ENTITY_STREAM_ROUTE = 'dh_auditor_show_entity_stream';

    public const string TRANSACTION_STREAM_ROUTE = 'dh_auditor_show_transaction_stream';

    public const string USER_STREAM_ROUTE = 'dh_auditor_show_user_stream';

    public function __construct(
        private readonly DoctrineProvider $provider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly bool $viewerEnabled,
    ) {}

    /**
     * Returns the URL of the audit stream of an entity.
     *
     * - an entity object: the stream of that object (null while it has no identifier yet);
     * - a class name: the stream of the whole class, or of one object when $id is given.
     *
     * @param class-string|object                                   $entity
     * @param array<string, null|bool|float|int|string|\Stringable> $filters viewer filters added to the query string
     *                                                                       (type, user, field, date_from, date_to, page)
     */
    public function generateEntityUrl(object|string $entity, int|string|\Stringable|null $id = null, array $filters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        $class = DoctrineHelper::getRealClassName($entity);
        if (!$this->isViewerAvailable() || !$this->provider->isAuditable($class)) {
            return null;
        }

        if (\is_object($entity)) {
            $id ??= $this->getObjectId($class, $entity);
            if (null === $id) {
                return null;
            }
        }

        $parameters = array_filter($filters, static fn (mixed $value): bool => null !== $value && '' !== $value);
        $parameters['entity'] = UrlHelper::namespaceToParam($class);
        if (null !== $id && '' !== (string) $id) {
            $parameters['id'] = (string) $id;
        }

        return $this->urlGenerator->generate(self::ENTITY_STREAM_ROUTE, $parameters, $referenceType);
    }

    /**
     * Returns the URL of the page grouping every change made in one database transaction
     * ($entry->transactionId).
     */
    public function generateTransactionUrl(string $transactionId, int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        if ('' === $transactionId || !$this->isViewerAvailable()) {
            return null;
        }

        return $this->urlGenerator->generate(self::TRANSACTION_STREAM_ROUTE, ['hash' => $transactionId], $referenceType);
    }

    /**
     * Returns the URL of the activity timeline of one user ($entry->userId, the recorded blame_id).
     */
    public function generateUserUrl(int|string $userId, int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        if ('' === (string) $userId || !$this->isViewerAvailable()) {
            return null;
        }

        return $this->urlGenerator->generate(self::USER_STREAM_ROUTE, ['id' => (string) $userId], $referenceType);
    }

    private function isViewerAvailable(): bool
    {
        return $this->viewerEnabled && $this->provider->getAuditor()->getConfiguration()->enabled;
    }

    private function getObjectId(string $class, object $entity): ?string
    {
        $entityManager = $this->provider->getAuditingServiceForEntity($class)->getEntityManager();
        $id = $this->id($entityManager, $entity);

        return null === $id || '' === $id || !\is_scalar($id) ? null : (string) $id;
    }
}
