<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use DH\DoctrineAuditBundle\Manager\AuditManager;
use DH\DoctrineAuditBundle\Tests\BaseTest;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use Exception;
use Gedmo;

/**
 * @internal
 */
final class UpdateHelperTest extends BaseTest
{
    public function testCreateAuditTable(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);
        $manager = new AuditManager($configuration, $helper);
        $reader = $this->getReader($this->getAuditConfiguration());
        $updater = new UpdateHelper($manager, $reader);
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
        $helper = new AuditHelper($configuration);
        $manager = new AuditManager($configuration, $helper);
        $reader = $this->getReader($this->getAuditConfiguration());
        $updater = new UpdateHelper($manager, $reader);
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
        $expected = $helper->getAuditTableColumns();
        foreach ($expected as $name => $options) {
            self::assertTrue($authorAuditTable->hasColumn($name), 'audit table has a column named "'.$name.'".');
        }

        // check expected indices
        $expected = $helper->getAuditTableIndices('author_audit');
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
        $helper = new AuditHelper($configuration);
        $manager = new AuditManager($configuration, $helper);
        $reader = $this->getReader($this->getAuditConfiguration());
        $updater = new UpdateHelper($manager, $reader);
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

        // new expected structure
        $expectedColumns = [
            'id' => [
                'type' => Type::INTEGER,
                'options' => [
                    'autoincrement' => true,
                    'unsigned' => true,
                ],
            ],
            'type' => [
                'type' => Type::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 10,
                ],
            ],
            'object_id' => [
                'type' => Type::STRING,
                'options' => [
                    'notnull' => true,
                    'length' => 50,
                ],
            ],
            'diffs' => [
                'type' => Type::JSON_ARRAY,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'blame_id' => [
                'type' => Type::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'unsigned' => true,
                ],
            ],
            'blame_user' => [
                'type' => Type::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 100,
                ],
            ],
            'created_at' => [
                'type' => Type::DATETIME,
                'options' => [
                    'notnull' => true,
                ],
            ],
            'locale' => [
                'type' => Type::STRING,
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 5,
                ],
            ],
            'version' => [
                'type' => Type::INTEGER,
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

        $helper = $this->createMock(AuditHelper::class);
        $helper
            ->method('getAuditTableColumns')
            ->willReturn($expectedColumns)
        ;
        $helper
            ->method('getAuditTableIndices')
            ->willReturn($expectedIndices)
        ;
        $manager->setHelper($helper);

        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');
        $toSchema = $updater->updateAuditTable($authorAuditTable, clone $schema);

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
        $expected = $helper->getAuditTableColumns();
        foreach ($expected as $name => $options) {
            self::assertTrue($authorAuditTable->hasColumn($name), 'audit table has a column named "'.$name.'".');
        }

        // check expected indices
        $expected = $helper->getAuditTableIndices('author_audit');
        foreach ($expected as $name => $options) {
            if ('primary' === $options['type']) {
                self::assertTrue($authorAuditTable->hasPrimaryKey(), 'audit table has a primary key named "'.$name.'".');
            } else {
                self::assertTrue($authorAuditTable->hasIndex($options['name']), 'audit table has an index named "'.$name.'".');
            }
        }
    }

    public function testGetConfiguration(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

        self::assertInstanceOf(AuditConfiguration::class, $helper->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
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

        $this->auditManager = new AuditManager($configuration, new AuditHelper($configuration));

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
