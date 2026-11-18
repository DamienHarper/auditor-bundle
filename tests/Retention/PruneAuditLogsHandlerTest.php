<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Retention;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\DefaultSchemaSetupTrait;
use DH\AuditorBundle\Retention\PruneAuditLogsHandler;
use DH\AuditorBundle\Retention\PruneAuditLogsMessage;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * @internal
 */
#[Small]
final class PruneAuditLogsHandlerTest extends TestCase
{
    use DefaultSchemaSetupTrait;

    private LockFactory $lockFactory;

    public function testOnlyEntitiesWithAPolicyArePrunedWithoutDefaultMaxAge(): void
    {
        $this->setEntityOptions([Post::class => ['max_entries' => 1]]);
        $this->insertEntries(Post::class, ['p1' => 'P3Y', 'p2' => 'P1D']);
        $this->insertEntries(Author::class, ['a1' => 'P10Y', 'a2' => 'P1D']);

        $this->createHandler()(new PruneAuditLogsMessage());

        $this->assertSame(['p2'], $this->remainingEntries(Post::class));
        // No policy and no default max age: untouched, however old.
        $this->assertSame(['a1', 'a2'], $this->remainingEntries(Author::class));
    }

    public function testDefaultMaxAgeAppliesToEntitiesWithoutMaxAge(): void
    {
        $this->setEntityOptions([Post::class => ['max_age' => 'P5Y']]);
        $this->insertEntries(Post::class, ['p1' => 'P6Y', 'p2' => 'P3Y']);
        $this->insertEntries(Author::class, ['a1' => 'P3Y', 'a2' => 'P1D']);

        $this->createHandler('P2Y')(new PruneAuditLogsMessage());

        // The entity max age wins over the default one.
        $this->assertSame(['p2'], $this->remainingEntries(Post::class));
        $this->assertSame(['a2'], $this->remainingEntries(Author::class));
    }

    public function testAnInvalidPolicyFailsBeforeAnyDeletion(): void
    {
        $this->setEntityOptions([Author::class => ['max_entries' => 1], Post::class => ['max_age' => 'P0D']]);
        $this->insertEntries(Author::class, ['a1' => 'P3Y', 'a2' => 'P1D']);

        try {
            $this->createHandler()(new PruneAuditLogsMessage());
            $this->fail('An exception should have been thrown.');
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString('Invalid retention "maxAge" value "P0D"', $invalidArgumentException->getMessage());
        }

        $this->assertSame(['a1', 'a2'], $this->remainingEntries(Author::class));
        // The lock is released after a failure.
        $this->assertTrue($this->lockFactory->createLock(PruneAuditLogsHandler::LOCK_NAME)->acquire());
    }

    public function testPruningIsSkippedWhileAuditCleanIsRunning(): void
    {
        $this->setEntityOptions([Post::class => ['max_entries' => 1]]);
        $this->insertEntries(Post::class, ['p1' => 'P3Y', 'p2' => 'P1D']);

        $lock = $this->lockFactory->createLock(PruneAuditLogsHandler::LOCK_NAME);
        $this->assertTrue($lock->acquire());

        $this->createHandler()(new PruneAuditLogsMessage());

        $this->assertSame(['p1', 'p2'], $this->remainingEntries(Post::class));
        $lock->release();
    }

    public function testAnInvalidDefaultMaxAgeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createHandler('1 year');
    }

    private function configureEntities(): void
    {
        $this->lockFactory = new LockFactory(new InMemoryStore());
        $this->setEntityOptions([]);
    }

    /**
     * @param array<string, array<string, mixed>> $options
     */
    private function setEntityOptions(array $options): void
    {
        $entities = [];
        foreach ([Author::class, Post::class] as $entity) {
            $entities[$entity] = ['enabled' => true] + ($options[$entity] ?? []);
        }

        $this->provider->getConfiguration()->setEntities($entities);
    }

    private function createHandler(?string $defaultMaxAge = null): PruneAuditLogsHandler
    {
        return new PruneAuditLogsHandler($this->provider, $defaultMaxAge, null, $this->lockFactory);
    }

    private function auditTable(string $entity): string
    {
        $configuration = $this->provider->getConfiguration();
        $this->assertInstanceOf(Configuration::class, $configuration);
        $table = new SchemaManager($this->provider)->resolveAuditTableName($entity, $configuration);
        \assert(\is_string($table));

        return $table;
    }

    /**
     * Inserts audit entries, in the given order, created the given ISO 8601 duration ago.
     *
     * @param array<string, string> $entries object_id => age
     */
    private function insertEntries(string $entity, array $entries): void
    {
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $jsonCast = $connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? '::jsonb' : '';
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->provider->getAuditor()->getConfiguration()->timezone));

        foreach ($entries as $objectId => $age) {
            $connection->executeStatement(
                \sprintf('INSERT INTO %s (schema_version, type, object_id, diffs, created_at) VALUES (?, ?, ?, ?%s, ?)', $this->auditTable($entity), $jsonCast),
                [2, 'update', $objectId, '{}', $now->sub(new \DateInterval($age))->format('Y-m-d H:i:s')],
            );
        }
    }

    /**
     * @return list<string> object ids of the remaining entries, in insertion order
     */
    private function remainingEntries(string $entity): array
    {
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();

        /** @var list<string> */
        return array_map(strval(...), $connection->fetchFirstColumn(\sprintf('SELECT object_id FROM %s ORDER BY id', $this->auditTable($entity))));
    }
}
