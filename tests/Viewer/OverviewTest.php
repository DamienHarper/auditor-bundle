<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Viewer\Overview;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

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
}
