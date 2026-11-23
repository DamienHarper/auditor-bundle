<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\Tests\Viewer\ObjectExistenceTest;
use Doctrine\DBAL\Types\Types;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tells which objects named by the phrases of a page no longer exist, to mark them "(deleted)".
 *
 * The page primes the references it shows: one query per class (WHERE id IN …) finds the ones that still exist. A
 * reference not primed, of a class that is not audited, with a composite identifier or with an identifier that is
 * neither an integer nor a string (UUID, ULID, enum, custom type), is never marked. The query goes through the entity
 * manager: an object hidden by a Doctrine SQL filter (soft delete, tenant) is marked as deleted.
 *
 * @see ObjectExistenceTest
 */
final class ObjectExistence implements ResetInterface
{
    private const array INTEGER_TYPES = [Types::INTEGER, Types::SMALLINT, Types::BIGINT];

    /**
     * Identifier types compared as they are written in the audit tables; the others (UUID, ULID, enums, custom types)
     * are converted by Doctrine, and their objects are never checked.
     */
    private const array STRING_TYPES = [Types::STRING, Types::ASCII_STRING, Types::GUID, Types::TEXT];

    /**
     * @var array<string, array<string, bool>> class => id => deleted
     */
    private array $deleted = [];

    public function __construct(private readonly DoctrineProvider $provider) {}

    /**
     * @param iterable<ObjectReference> $references
     */
    public function prime(iterable $references): void
    {
        $ids = [];
        foreach ($references as $reference) {
            if (!isset($this->deleted[$reference->class][$reference->id])) {
                $ids[$reference->class][$reference->id] = true;
            }
        }

        foreach ($ids as $class => $classIds) {
            foreach ($this->existing($class, array_map(strval(...), array_keys($classIds))) ?? [] as $id => $exists) {
                $this->deleted[$class][$id] = !$exists;
            }
        }
    }

    public function isDeleted(ObjectReference $reference): bool
    {
        return $this->deleted[$reference->class][$reference->id] ?? false;
    }

    public function reset(): void
    {
        $this->deleted = [];
    }

    /**
     * @param list<string> $ids
     *
     * @return null|array<string, bool> id => exists; null when the class cannot be checked
     */
    private function existing(string $class, array $ids): ?array
    {
        if (!class_exists($class) || !$this->provider->isAuditable($class)) {
            return null;
        }

        $entityManager = $this->provider->getAuditingServiceForEntity($class)->getEntityManager();
        $metadata = $entityManager->getClassMetadata($class);
        if ($metadata->isIdentifierComposite || 1 !== \count($metadata->getIdentifierFieldNames())) {
            return null;
        }

        $field = $metadata->getSingleIdentifierFieldName();
        $type = $metadata->getTypeOfField($field);
        $integer = \in_array($type, self::INTEGER_TYPES, true);
        if (!$integer && !\in_array($type, self::STRING_TYPES, true)) {
            return null;
        }

        $exists = array_fill_keys($ids, false);
        $values = $integer ? array_values(array_map(intval(...), array_filter($ids, static fn (string $id): bool => 1 === preg_match('/^-?\d+$/', $id)))) : $ids;
        if ([] === $values) {
            return $exists;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $entityManager->createQueryBuilder()
            ->select('o.'.$field.' AS id')
            ->from($class, 'o')
            ->where('o.'.$field.' IN (:ids)')
            ->setParameter('ids', $values)
            ->getQuery()
            ->getScalarResult()
        ;

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (\is_int($id) || \is_string($id)) {
                $exists[(string) $id] = true;
            }
        }

        return $exists;
    }
}
