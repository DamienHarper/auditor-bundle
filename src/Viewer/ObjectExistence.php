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
 * reference not primed, of a class Doctrine does not know or with a composite identifier, is never marked.
 *
 * @see ObjectExistenceTest
 */
final class ObjectExistence implements ResetInterface
{
    private const array INTEGER_TYPES = [Types::INTEGER, Types::SMALLINT, Types::BIGINT];

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
        $integer = \in_array($metadata->getTypeOfField($field), self::INTEGER_TYPES, true);

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
