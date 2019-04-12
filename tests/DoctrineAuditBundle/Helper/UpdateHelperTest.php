<?php

namespace DH\DoctrineAuditBundle\Tests\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use DH\DoctrineAuditBundle\Tests\BaseTest;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use Gedmo;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\AuditManager
 * @covers \DH\DoctrineAuditBundle\DBAL\AuditLogger
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\AuditSubscriber
 * @covers \DH\DoctrineAuditBundle\EventSubscriber\CreateSchemaListener
 * @covers \DH\DoctrineAuditBundle\Helper\AuditHelper
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\Helper\UpdateHelper
 * @covers \DH\DoctrineAuditBundle\Reader\AuditEntry
 * @covers \DH\DoctrineAuditBundle\Reader\AuditReader
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 * @covers \DH\DoctrineAuditBundle\User\User
 */
class UpdateHelperTest extends BaseTest
{
    /**
     * @var string
     */
    protected $fixturesPath = __DIR__.'/../Fixtures';

    private function getTable(array $tables, string $name): ?Table
    {
        foreach ($tables as $table) {
            if ($name === $table->getName()) {
                return $table;
            }
        }

        return null;
    }

    public function testCreateAuditTable(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);
        $manager = new AuditManager($configuration, $helper);
        $updater = new UpdateHelper($manager);
        $schemaManager = $em->getConnection()->getSchemaManager();

        $authorTable = $this->getTable($schemaManager->listTables(), 'author');
        $this->assertNull($this->getTable($schemaManager->listTables(), 'author_audit'), 'author_audit does not exist yet.');

        $fromSchema = $schemaManager->createSchema();
        $toSchema = clone  $fromSchema;
        $updater->createAuditTable($toSchema, $authorTable);

        // apply changes
        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            $statement = $em->getConnection()->prepare($query);
            $statement->execute();
        }

        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');
        $this->assertNotNull($authorAuditTable, 'author_audit table has been created.');
    }

    /**
     * @depends testCreateAuditTable
     */
    public function testCreateAuditTableHasExpectedStructure(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);
        $manager = new AuditManager($configuration, $helper);
        $updater = new UpdateHelper($manager);
        $schemaManager = $em->getConnection()->getSchemaManager();

        $authorTable = $this->getTable($schemaManager->listTables(), 'author');

        $fromSchema = $schemaManager->createSchema();
        $toSchema = clone  $fromSchema;
        $updater->createAuditTable($toSchema, $authorTable);

        // apply changes
        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            $statement = $em->getConnection()->prepare($query);
            $statement->execute();
        }

        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');

        // check expected columns
        $expected = $helper->getAuditTableColumns();
        foreach ($expected as $name => $options) {
            $this->assertTrue($authorAuditTable->hasColumn($name), 'audit table has a column named "'.$name.'".');
        }

        // check expected indices
        $expected = $helper->getAuditTableIndices('author_audit');
        foreach ($expected as $name => $options) {
            if ('primary' === $options['type']) {
                $this->assertTrue($authorAuditTable->hasPrimaryKey(), 'audit table has a primary key named "'.$name.'".');
            } else {
                $this->assertTrue($authorAuditTable->hasIndex($options['name']), 'audit table has an index named "'.$name.'".');
            }
        }
    }

    /**
     * @depends testCreateAuditTableHasExpectedStructure
     */
    public function testUpdateAuditTable(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);
        $manager = new AuditManager($configuration, $helper);
        $updater = new UpdateHelper($manager);
        $schemaManager = $em->getConnection()->getSchemaManager();

        $authorTable = $this->getTable($schemaManager->listTables(), 'author');

        $fromSchema = $schemaManager->createSchema();
        $toSchema = clone  $fromSchema;
        $updater->createAuditTable($toSchema, $authorTable);

        // apply changes
        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            $statement = $em->getConnection()->prepare($query);
            $statement->execute();
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
                'type' => Type::INTEGER,
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
//            'ip' => [
//                'type' => Type::STRING,
//                'options' => [
//                    'default' => null,
//                    'notnull' => false,
//                    'length' => 45,
//                ],
//            ],
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

        $fromSchema = $schemaManager->createSchema();
        $toSchema = clone  $fromSchema;
        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');
        $updater->updateAuditTable($schemaManager, $fromSchema, $authorAuditTable, $em);

        // apply changes
        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            $statement = $em->getConnection()->prepare($query);
            $statement->execute();
        }

        $authorAuditTable = $this->getTable($schemaManager->listTables(), 'author_audit');

        // check expected columns
        $expected = $helper->getAuditTableColumns();
        foreach ($expected as $name => $options) {
            $this->assertTrue($authorAuditTable->hasColumn($name), 'audit table has a column named "'.$name.'".');
        }

        // check expected indices
        $expected = $helper->getAuditTableIndices('author_audit');
        foreach ($expected as $name => $options) {
            if ('primary' === $options['type']) {
                $this->assertTrue($authorAuditTable->hasPrimaryKey(), 'audit table has a primary key named "'.$name.'".');
            } else {
                $this->assertTrue($authorAuditTable->hasIndex($options['name']), 'audit table has an index named "'.$name.'".');
            }
        }
    }

    public function testGetConfiguration(): void
    {
        $em = $this->getEntityManager();
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);

        $this->assertInstanceOf(AuditConfiguration::class, $helper->getConfiguration(), 'configuration instanceof AuditConfiguration::class');
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

        $this->setAuditConfiguration($this->createAuditConfiguration());
        $configuration = $this->getAuditConfiguration();

        $this->auditManager = new AuditManager($configuration, new AuditHelper($configuration));

        // get rid of more global state
        $evm = $connection->getEventManager();
        foreach ($evm->getListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $evm->removeEventListener([$event], $listener);
            }
        }

        $this->em = EntityManager::create($connection, $config);

        return $this->em;
    }
}
