<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\Auditor\Contract\FieldLabelResolverInterface;
use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\History\Change;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\AuditorBundle\Tests\Twig\HistoryExtensionTest;
use Twig\Attribute\AsTwigFunction;

/**
 * Field-level history in templates: who changed a field last, and its label.
 *
 * @see HistoryExtensionTest
 */
final readonly class HistoryExtension
{
    public function __construct(
        private Reader $reader,
        private DoctrineProvider $provider,
        private FieldLabelResolverInterface $fieldLabelResolver,
    ) {}

    /**
     * Last change of each field of an audited entity (its creation for a field never changed since), or null for a
     * field without audit entry. Empty when the current user cannot view the audits of the entity.
     *
     * @param list<string> $fields
     *
     * @return array<string, ?Change>
     */
    #[AsTwigFunction('dh_auditor_last_changes')]
    public function lastChanges(object $entity, array $fields): array
    {
        $class = DoctrineHelper::getRealClassName($entity);
        $id = $this->identifier($entity, $class);
        if (null === $id || !$this->provider->isAuditable($class)) {
            return array_fill_keys($fields, null);
        }

        try {
            return $this->reader->getLastChanges($class, $id, $fields);
        } catch (AccessDeniedException) {
            return array_fill_keys($fields, null);
        }
    }

    #[AsTwigFunction('dh_auditor_last_change')]
    public function lastChange(object $entity, string $field): ?Change
    {
        return $this->lastChanges($entity, [$field])[$field] ?? null;
    }

    /**
     * Label of a field, from the "dh_auditor_fields" translation domain.
     *
     * @param class-string|object $entity
     */
    #[AsTwigFunction('dh_auditor_field_label')]
    public function fieldLabel(object|string $entity, string $field): string
    {
        return $this->fieldLabelResolver->resolve(DoctrineHelper::getRealClassName($entity), $field);
    }

    private function identifier(object $entity, string $class): ?string
    {
        $values = $this->provider->getAuditingServiceForEntity($class)->getEntityManager()->getClassMetadata($class)->getIdentifierValues($entity);
        $id = [] === $values ? null : reset($values);

        return match (true) {
            \is_scalar($id), $id instanceof \Stringable => (string) $id,
            default => null,
        };
    }
}
