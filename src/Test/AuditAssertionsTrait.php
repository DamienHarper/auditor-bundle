<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Test;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use PHPUnit\Framework\Assert;

/**
 * Assertions on the audit entries written by the code under test, for a KernelTestCase or a WebTestCase:
 *
 *     final class UserTest extends KernelTestCase
 *     {
 *         use AuditAssertionsTrait;
 *
 *         public function testRename(): void
 *         {
 *             // ... flush a change of the name of user 42
 *
 *             self::assertAuditCount(1, User::class, 42, 'update');
 *             self::assertAuditFieldChanged(User::class, 42, 'name', 'John', 'Jane');
 *         }
 *     }
 *
 * The entries are read from the audit tables without the role checks nor the "read_scope" of the reader.
 */
trait AuditAssertionsTrait
{
    /**
     * Audit entries of $entity, most recent first, optionally of one object and of one type ("insert", "update",
     * "remove", "associate", "dissociate").
     *
     * @param class-string $entity
     *
     * @return list<Entry>
     */
    public static function getAuditEntries(string $entity, int|string|null $id = null, ?string $type = null): array
    {
        $reader = static::getContainer()->get(Reader::class);
        Assert::assertInstanceOf(Reader::class, $reader);

        $provider = $reader->getProvider();
        $query = new Query(
            $reader->getEntityAuditTableName($entity),
            $provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection(),
            $provider->getAuditor()->getConfiguration()->timezone,
        );
        $query->addOrderBy(Query::CREATED_AT, 'DESC')->addOrderBy(Query::ID, 'DESC');

        if (null !== $id) {
            $query->addFilter(new SimpleFilter(Query::OBJECT_ID, (string) $id));
        }

        if (null !== $type) {
            $query->addFilter(new SimpleFilter(Query::TYPE, $type));
        }

        return array_values($query->execute());
    }

    /**
     * @param class-string $entity
     */
    public static function assertAuditCount(int $expected, string $entity, int|string|null $id = null, ?string $type = null, string $message = ''): void
    {
        Assert::assertCount($expected, self::getAuditEntries($entity, $id, $type), '' !== $message ? $message : \sprintf('%d audit entries of "%s"%s%s.', $expected, $entity, null === $id ? '' : ' #'.$id, null === $type ? '' : ' of type "'.$type.'"'));
    }

    /**
     * Asserts that the most recent audit entry of the object that changed $field recorded it from $old to $new, as
     * stored in the diff (a #[DiffLabel] value is an array with "value" and "label", a #[Mask] value is masked).
     *
     * @param class-string $entity
     */
    public static function assertAuditFieldChanged(string $entity, int|string $id, string $field, mixed $old, mixed $new, string $message = ''): void
    {
        foreach (self::getAuditEntries($entity, $id) as $entry) {
            $diffs = $entry->getDiffs();
            if (\array_key_exists($field, $diffs) && !$entry->isDeepDiff($field)) {
                Assert::assertSame(['old' => $old, 'new' => $new], ['old' => $diffs[$field]['old'] ?? null, 'new' => $diffs[$field]['new'] ?? null], '' !== $message ? $message : \sprintf('Last change of "%s" of "%s" #%s.', $field, $entity, $id));

                return;
            }
        }

        Assert::fail('' !== $message ? $message : \sprintf('No audit entry of "%s" #%s changed "%s".', $entity, $id, $field));
    }
}
