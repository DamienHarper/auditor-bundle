<?php

namespace DH\DoctrineAuditBundle\Tests;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Tools\SchemaTool;
use Gedmo;
use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    /**
     * @var null|Connection
     */
    protected static $conn;

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var SchemaTool
     */
    private $schemaTool;

    protected $fixturesPath;

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    public function setUp(): void
    {
        $this->getEntityManager();
        $this->getSchemaTool();
        $this->setUpEntitySchema();
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     */
    public function tearDown(): void
    {
        $this->tearDownEntitySchema();
        $this->em = null;
        $this->schemaTool = null;
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

        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver([
            $this->fixturesPath,
        ], false));

        Gedmo\DoctrineExtensions::registerAnnotations();

        $connection = $this->_getConnection();

        // get rid of more global state
        $evm = $connection->getEventManager();
        foreach ($evm->getListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $evm->removeEventListener([$event], $listener);
            }
        }

        $this->em = EntityManager::create($connection, $config);

        if (isset($this->customTypes) and \is_array($this->customTypes)) {
            foreach ($this->customTypes as $customTypeName => $customTypeClass) {
                if (!Type::hasType($customTypeName)) {
                    Type::addType($customTypeName, $customTypeClass);
                }
                $this->em->getConnection()->getDatabasePlatform()->registerDoctrineTypeMapping('db_'.$customTypeName, $customTypeName);
            }
        }

        return $this->em;
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     *
     * @return SchemaTool
     */
    protected function getSchemaTool(): SchemaTool
    {
        if (null !== $this->schemaTool) {
            return $this->schemaTool;
        }

        return $this->schemaTool = new SchemaTool($this->getEntityManager());
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return null|Connection
     */
    protected function _getConnection(): Connection
    {
        if (!isset(self::$conn)) {
            if (isset(
                $GLOBALS['db_type'],
                $GLOBALS['db_username'],
                $GLOBALS['db_password'],
                $GLOBALS['db_host'],
                $GLOBALS['db_name'],
                $GLOBALS['db_port']
            )) {
                $params = [
                    'driver' => $GLOBALS['db_type'],
                    'user' => $GLOBALS['db_username'],
                    'password' => $GLOBALS['db_password'],
                    'host' => $GLOBALS['db_host'],
                    'dbname' => $GLOBALS['db_name'],
                    'port' => $GLOBALS['db_port'],
                ];

                $tmpParams = $params;
                $dbname = $params['dbname'];
                unset($tmpParams['dbname']);

                $conn = DriverManager::getConnection($tmpParams);
                $platform = $conn->getDatabasePlatform();

                if ($platform->supportsCreateDropDatabase()) {
                    $conn->getSchemaManager()->dropAndCreateDatabase($dbname);
                } else {
                    $sm = $conn->getSchemaManager();
                    $schema = $sm->createSchema();
                    $stmts = $schema->toDropSql($conn->getDatabasePlatform());
                    foreach ($stmts as $stmt) {
                        $conn->exec($stmt);
                    }
                }

                $conn->close();
            } else {
                $params = [
                    'driver' => 'pdo_sqlite',
                    'memory' => true,
                ];
            }

            self::$conn = DriverManager::getConnection($params);
        }

        return self::$conn;
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    protected function setUpEntitySchema(): void
    {
        $classes = $this->getEntityManager()->getMetadataFactory()->getAllMetadata();

        $this->getSchemaTool()->createSchema($classes);
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     */
    protected function tearDownEntitySchema(): void
    {
        $classes = $this->getEntityManager()->getMetadataFactory()->getAllMetadata();

        $this->getSchemaTool()->dropSchema($classes);
    }
}
