<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use DH\AuditorBundle\Viewer\Overview;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @internal
 */
#[Small]
final class OverviewTest extends TestCase
{
    use BlogSchemaSetupTrait;

    public function testActionCountOfAMigratedTable(): void
    {
        $reader = new Reader($this->provider);
        $connection = $this->provider->getStorageServiceForEntity(Tag::class)->getEntityManager()->getConnection();
        $table = $reader->getEntityAuditTableName(Tag::class);

        $expected = (int) $connection->fetchOne('SELECT COUNT(DISTINCT transaction_id) FROM '.$table);
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, new Overview($reader)->actionCount(Tag::class));
    }

    public function testActionCountOfALegacyTable(): void
    {
        $reader = new Reader($this->provider);
        $connection = $this->provider->getStorageServiceForEntity(Tag::class)->getEntityManager()->getConnection();
        $table = $reader->getEntityAuditTableName(Tag::class);

        // a table not migrated to the schema v2 identifies its transactions in transaction_hash
        $connection->executeStatement(\sprintf('ALTER TABLE %s RENAME COLUMN transaction_id TO transaction_hash', $table));

        try {
            $connection->executeStatement(\sprintf('UPDATE %s SET transaction_hash = ?', $table), [sha1('one')]);
            $connection->executeStatement(\sprintf('UPDATE %s SET transaction_hash = ? WHERE id = (SELECT MIN(id) FROM %1$s)', $table), [sha1('two')]);

            $this->assertSame(2, new Overview($reader)->actionCount(Tag::class));
        } finally {
            // the SQLite file outlives the test
            $connection->executeStatement(\sprintf('ALTER TABLE %s RENAME COLUMN transaction_hash TO transaction_id', $table));
        }
    }

    public function testActionCountCountsEachEntryWithoutTransactionAsAnAction(): void
    {
        $reader = new Reader($this->provider);
        $connection = $this->provider->getStorageServiceForEntity(Tag::class)->getEntityManager()->getConnection();
        $table = $reader->getEntityAuditTableName(Tag::class);

        // two entries of the same transaction lose their transaction id: each becomes an action of its own
        $transaction = $connection->fetchOne(\sprintf('SELECT transaction_id FROM %s GROUP BY transaction_id HAVING COUNT(*) > 1', $table));
        $this->assertIsString($transaction);
        $connection->executeStatement(\sprintf('UPDATE %s SET transaction_id = NULL WHERE transaction_id = ?', $table), [$transaction]);

        $expected = (int) $connection->fetchOne(\sprintf('SELECT COUNT(DISTINCT transaction_id) FROM %s', $table))
            + (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE transaction_id IS NULL', $table));
        $this->assertSame($expected, new Overview($reader)->actionCount(Tag::class));
    }

    public function testActionCountOfATableBeingMigrated(): void
    {
        $reader = new Reader($this->provider);
        $connection = $this->provider->getStorageServiceForEntity(Tag::class)->getEntityManager()->getConnection();
        $table = $reader->getEntityAuditTableName(Tag::class);
        $expected = (int) $connection->fetchOne(\sprintf('SELECT COUNT(DISTINCT transaction_id) FROM %s', $table)) + 1;

        // while a table is migrated to the schema v2, its older entries only have a transaction_hash
        $connection->executeStatement(\sprintf('ALTER TABLE %s ADD COLUMN transaction_hash VARCHAR(40) DEFAULT NULL', $table));

        try {
            $connection->executeStatement(\sprintf('UPDATE %s SET transaction_id = NULL, transaction_hash = ? WHERE id = (SELECT MIN(id) FROM %1$s)', $table), [sha1('legacy')]);

            $this->assertSame($expected, new Overview($reader)->actionCount(Tag::class));
        } finally {
            // the SQLite file outlives the test
            $connection->executeStatement(\sprintf('ALTER TABLE %s DROP COLUMN transaction_hash', $table));
        }
    }

    public function testAReadScopeCountsTheActionsInItsScopeWithoutTheCache(): void
    {
        $reader = new Reader($this->provider);
        $all = $reader->createQuery(Tag::class, ['page_size' => null])->countTransactions();
        $scoped = $reader->createQuery(Tag::class, ['object_id' => '1', 'page_size' => null])->countTransactions();
        $this->assertGreaterThan($scoped, $all, 'The scope hides actions.');

        $overview = new Overview($reader, new ActivityGraphProvider(7, 'bottom', true, 300, new ArrayAdapter()));
        $scope = static function (Query $query): void {
            $query->addFilter(new SimpleFilter(Query::OBJECT_ID, '1'));
        };

        $this->provider->getConfiguration()->setReadScope($scope);
        $this->assertSame($scoped, $overview->actionCount(Tag::class), 'Counted in the scope.');

        $this->provider->getConfiguration()->setReadScope(null);
        $this->assertSame($all, $overview->actionCount(Tag::class), 'The scoped count is not cached.');

        $this->provider->getConfiguration()->setReadScope($scope);
        $this->assertSame($scoped, $overview->actionCount(Tag::class), 'The cached count is not read in a scope.');
    }
}
