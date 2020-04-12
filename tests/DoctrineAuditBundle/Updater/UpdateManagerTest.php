<?php

namespace DH\DoctrineAuditBundle\Tests\Updater;

use DH\DoctrineAuditBundle\Helper\SchemaHelper;
use DH\DoctrineAuditBundle\Tests\BaseTest;
use DH\DoctrineAuditBundle\Transaction\TransactionManager;
use DH\DoctrineAuditBundle\Updater\UpdateManager;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use Exception;
use Gedmo;

/**
 * @internal
 */
final class UpdateManagerTest extends BaseTest
{
    public function testCreateAuditTable(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $manager = new TransactionManager($configuration);
        $reader = $this->getReader($this->getAuditConfiguration());
        $updater = new UpdateManager($manager, $reader);
        $schemaManager = $em->getConnection()->getSchemaManager();

        $authorTable = $this->getTable($schemaManager->listTables(), 'author');
        self::assertNull($this->getTable($schemaManager->listTables(), 'author_audit'), 'author_audit does not exist yet.');

        $schema = $updater->createAuditTable($authorTable, $schemaManager->createSchema());

        // apply changes
        $sql = $schema->toSql($schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            try {
                $statement = $em->getConnection()->prepare($query);
                $statement->execute();
            } catch (Exception $e) {
            }
        }

        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');
        self::assertNotNull($authorAuditTable, 'author_audit table has been created.');
    }

    /**
     * @depends testCreateAuditTable
     */
    public function testCreateAuditTableHasExpectedStructure(): void
    {
        $configuration = $this->getAuditConfiguration();
        $em = $configuration->getEntityManager();
        $manager = new TransactionManager($configuration);
        $reader = $this->getReader($this->getAuditConfiguration());
        $updater = new \DH\DoctrineAuditBundle\Updater\UpdateManager($manager, $reader);
        $schemaManager = $em->getConnection()->getSchemaManager();

        $authorTable = $this->getTable($schemaManager->listTables(), 'author');

        $schema = $updater->createAuditTable($authorTable, $schemaManager->createSchema());

        // apply changes
        $sql = $schema->toSql($schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            try {
                $statement = $em->getConnection()->prepare($query);
                $statement->execute();
            } catch (Exception $e) {
            }
        }

        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');

        // check expected columns
        $expected = SchemaHelper::getAuditTableColumns();
        foreach ($expected as $name => $options) {
            self::assertTrue($authorAuditTable->hasColumn($name), 'audit table has a column named "'.$name.'".');
        }

        // check expected indices
        $expected = SchemaHelper::getAuditTableIndices('author_audit');
        foreach ($expected as $name => $options) {
            if ('primary' === $options['type']) {
                self::assertTrue($authorAuditTable->hasPrimaryKey(), 'audit table has a primary key named "'.$name.'".');
            } else {
                self::assertTrue($authorAuditTable->hasIndex($options['name']), 'audit table has an index named "'.$name.'".');
            }
        }
    }

    /**
     * @depends testCreateAuditTableHasExpectedStructure
     */
    public function testUpdateAuditTable(): void
    {
        $configuration = $this->getAuditConfiguration();
        $em = $configuration->getEntityManager();
        $manager = new TransactionManager($configuration);
        $reader = $this->getReader($this->getAuditConfiguration());
        $updater = new UpdateManager($manager, $reader);
        $schemaManager = $em->getConnection()->getSchemaManager();

        $authorTable = $this->getTable($schemaManager->listTables(), 'author');

        $schema = $updater->createAuditTable($authorTable, $schemaManager->createSchema());

        // apply changes
        $sql = $schema->toSql($schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            try {
                $statement = $em->getConnection()->prepare($query);
                $statement->execute();
            } catch (Exception $e) {
            }
        }

        $expectedColumns = SchemaHelper::getAuditTableColumns();
        foreach ($schemaManager->listTables() as $table) {
            if (!preg_match('#_audit$#', $table->getName())) {
                continue;
            }

            // check expected columns
            foreach ($expectedColumns as $name => $options) {
                self::assertTrue($table->hasColumn($name), '"'.$table->getName().'" audit table has a column named "'.$name.'".');
            }

            // check expected indices
            $expectedIndices = SchemaHelper::getAuditTableIndices($table->getName());
            foreach ($expectedIndices as $name => $options) {
                if ('primary' === $options['type']) {
                    self::assertTrue($table->hasPrimaryKey(), 'audit table has a primary key named "'.$name.'".');
                } else {
                    self::assertTrue($table->hasIndex($options['name']), 'audit table has an index named "'.$name.'".');
                }
            }
        }

        // new expected structure
        $expectedColumns = [
            'id' => [
                'type' => Types::INTEGER,
                'options' => [
                    'autoincrement' => true,
                    'unsigned' => true,
                ],
            ],
            'type' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 10,
                ],
            ],
            'object_id' => [
                'type' => Types::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 50,
                ],
            ],
            'diffs' => [
                'type' => Types::JSON_ARRAY,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'blame_id' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'unsigned' => true,
                ],
            ],
            'blame_user' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 100,
                ],
            ],
            'created_at' => [
                'type' => Types::DATETIME_IMMUTABLE,
                'options' => [
                    'notnull' => true,
                ],
            ],
            'locale' => [
                'type' => Types::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 5,
                ],
            ],
            'version' => [
                'type' => Types::INTEGER,
                'options' => [
                    'default' => null,
                    'notnull' => true,
                ],
            ],
        ];

        $tablename = 'author_audit';
        $expectedIndices = [
            'id' => [
                'type' => 'primary',
            ],
            'type' => [
                'type' => 'index',
                'name' => 'type_'.md5($tablename).'_idx',
            ],
            'object_id' => [
                'type' => 'index',
                'name' => 'object_id_'.md5($tablename).'_idx',
            ],
            'blame_id' => [
                'type' => 'index',
                'name' => 'blame_id_'.md5($tablename).'_idx',
            ],
            'created_at' => [
                'type' => 'index',
                'name' => 'created_at_'.md5($tablename).'_idx',
            ],
        ];

        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');
        $toSchema = $updater->updateAuditTable($authorAuditTable, clone $schema, $expectedColumns, $expectedIndices);

        // apply changes
        $sql = $schema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            try {
                $statement = $em->getConnection()->prepare($query);
                $statement->execute();
            } catch (Exception $e) {
            }
        }

        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');

        // check expected columns
        foreach ($expectedColumns as $name => $options) {
            self::assertTrue($authorAuditTable->hasColumn($name), 'audit table has a column named "'.$name.'".');
        }

        // check expected indices
        foreach ($expectedIndices as $name => $options) {
            if ('primary' === $options['type']) {
                self::assertTrue($authorAuditTable->hasPrimaryKey(), 'audit table has a primary key named "'.$name.'".');
            } else {
                self::assertTrue($authorAuditTable->hasIndex($options['name']), 'audit table has an index named "'.$name.'".');
            }
        }
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     *
     * @return EntityManager
     */
    protected function getEntityManager(): EntityManager
    {
        if (null !== $this->em) {
            return $this->em;
        }

        $config = new Configuration();
        $config->setMetadataCacheImpl(new ArrayCache());
        $config->setQueryCacheImpl(new ArrayCache());
        $config->setProxyDir(__DIR__.'/Proxies');
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        $config->setProxyNamespace('DH\DoctrineAuditBundle\Tests\Proxies');

        $fixturesPath = \is_array($this->fixturesPath) ? $this->fixturesPath : [$this->fixturesPath];
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver($fixturesPath, false));

        Gedmo\DoctrineExtensions::registerAnnotations();

        $connection = $this->getConnection();

        $this->em = EntityManager::create($connection, $config);

        $this->setAuditConfiguration($this->createAuditConfiguration([], $this->em));
        $configuration = $this->getAuditConfiguration();

        $this->transactionManager = new TransactionManager($configuration);

        // get rid of more global state
        $evm = $connection->getEventManager();
        foreach ($evm->getListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $evm->removeEventListener([$event], $listener);
            }
        }

        return $this->em;
    }

    private function getTable(array $tables, string $name): ?Table
    {
        foreach ($tables as $table) {
            if ($name === $table->getName()) {
                return $table;
            }
        }

        return null;
    }
}
