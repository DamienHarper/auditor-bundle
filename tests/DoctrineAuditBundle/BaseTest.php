<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\Annotation\AnnotationLoader;
use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Event\AuditSubscriber;
use DH\DoctrineAuditBundle\Event\CreateSchemaListener;
use DH\DoctrineAuditBundle\Event\DoctrineSubscriber;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Helper\UpdateHelper;
use DH\DoctrineAuditBundle\Manager\AuditManager;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Tools\SchemaTool;
use Exception;
use Gedmo;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

abstract class BaseTest extends TestCase
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
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var string
     */
    protected $fixturesPath = [
        __DIR__.'/../../src/DoctrineAuditBundle/Annotation',
        __DIR__.'/Fixtures',
    ];

    protected $auditConfiguration;

    protected $auditManager;

    /**
     * @var SchemaTool
     */
    private $schemaTool;

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\Tools\ToolsException
     */
    protected function setUp(): void
    {
        $this->getEntityManager();
        $this->getSchemaTool();
        $this->setUpEntitySchema();
        $this->setUpAuditSchema();
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\ORMException
     */
    protected function tearDown(): void
    {
        $this->tearDownAuditSchema();
        $this->tearDownEntitySchema();
        $this->em = null;
        $this->schemaTool = null;
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
        $em = $this->getEntityManager();
        $schemaManager = $em->getConnection()->getSchemaManager();
        $fromSchema = $schemaManager->createSchema();
        $toSchema = clone $fromSchema;

        $tables = $fromSchema->getTables();
        foreach ($tables as $table) {
            $toSchema = $toSchema->dropTable($table->getName());
        }

        $sql = $fromSchema->getMigrateToSql($toSchema, $schemaManager->getDatabasePlatform());
        foreach ($sql as $query) {
            try {
                $statement = $em->getConnection()->prepare($query);
                $statement->execute();
            } catch (Exception $e) {
            }
        }
    }

    protected function setUpAuditSchema(): void
    {
        $configuration = $this->getAuditConfiguration();
        $helper = new AuditHelper($configuration);
        $manager = new AuditManager($configuration, $helper);
        $reader = $this->getReader($this->getAuditConfiguration());

        $updater = new UpdateHelper($manager, $reader);
        $updater->updateAuditSchema();
    }

    protected function tearDownAuditSchema(): void
    {
        $configuration = $this->getAuditConfiguration();
        $em = $configuration->getEntityManager();
        $schemaManager = $em->getConnection()->getSchemaManager();
        $schema = $schemaManager->createSchema();
        $fromSchema = clone $schema;

        $tables = $schemaManager->listTables();
        foreach ($tables as $table) {
            $regex = '#^'.$configuration->getTablePrefix().'.*'.$configuration->getTableSuffix().'$#';
            if (preg_match($regex, $table->getName())) {
                $schema->dropTable($table->getName());
            }
        }

        $sqls = $fromSchema->getMigrateToSql($schema, $schemaManager->getDatabasePlatform());

        foreach ($sqls as $sql) {
            try {
                $statement = $em->getConnection()->prepare($sql);
                $statement->execute();
            } catch (Exception $e) {
                // something bad happened here :/
            }
        }
    }

    protected function getAuditConfiguration(): AuditConfiguration
    {
        return $this->auditConfiguration;
    }

    protected function setAuditConfiguration(AuditConfiguration $configuration): void
    {
        $this->auditConfiguration = $configuration;
    }

    protected function createAuditConfiguration(array $options = [], ?EntityManager $entityManager = null): AuditConfiguration
    {
        $container = new ContainerBuilder();
        $em = $entityManager ?? $this->getEntityManager();

        return new AuditConfiguration(
            array_merge([
                'enabled' => true,
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [],
            ], $options),
            new TokenStorageUserProvider(new Security($container)),
            new RequestStack(),
            new FirewallMap($container, []),
            $em,
            new AnnotationLoader($em),
            new EventDispatcher()
        );
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
        $config->addFilter('soft-deleteable', Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter::class);

        $fixturesPath = \is_array($this->fixturesPath) ? $this->fixturesPath : [$this->fixturesPath];
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver($fixturesPath, false));

        Gedmo\DoctrineExtensions::registerAnnotations();

        $connection = $this->getSharedConnection();

        $this->em = EntityManager::create($connection, $config);

        $this->setAuditConfiguration($this->createAuditConfiguration([], $this->em));
        $configuration = $this->getAuditConfiguration();

        $this->auditManager = new AuditManager($configuration, new AuditHelper($configuration));

        $configuration->getEventDispatcher()->addSubscriber(new AuditSubscriber($this->auditManager));

        // get rid of more global state
        $evm = $connection->getEventManager();
        foreach ($evm->getListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $evm->removeEventListener([$event], $listener);
            }
        }
        $evm->addEventSubscriber(new DoctrineSubscriber($this->auditManager));
        $evm->addEventSubscriber(new CreateSchemaListener($this->auditManager, $this->getReader()));
        $evm->addEventSubscriber(new Gedmo\SoftDeleteable\SoftDeleteableListener());

        return $this->em;
    }

    protected function getSharedConnection(): Connection
    {
        if (null === self::$conn) {
            self::$conn = $this->getConnection();
        }

        if (false === self::$conn->ping()) {
            self::$conn->close();
            self::$conn->connect();
        }

        return self::$conn;
    }

    protected function getEventDispatcher(AuditManager $manager): EventDispatcherInterface
    {
        if (null !== $this->dispatcher) {
            $this->dispatcher = new EventDispatcher();
            $this->dispatcher->addSubscriber(new AuditSubscriber($manager));
        }

        return $this->dispatcher;
    }

    /**
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return null|Connection
     */
    protected function getConnection(): Connection
    {
        if (null !== self::$conn) {
            self::$conn->close();
            self::$conn = null;
        }

        $params = $this->getConnectionParameters();

        if (isset(
            $GLOBALS['db_type'],
            $GLOBALS['db_username'],
            $GLOBALS['db_password'],
            $GLOBALS['db_host'],
            $GLOBALS['db_name'],
            $GLOBALS['db_port']
        )) {
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
        }

        return DriverManager::getConnection($params);
    }

    protected function getConnectionParameters(): array
    {
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
        } else {
            $params = [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ];
        }

        return $params;
    }

    protected function getSecondaryEntityManager(): EntityManager
    {
        $connection = $this->getSecondaryConnection();

        $config = new Configuration();
        $config->setMetadataCacheImpl(new ArrayCache());
        $config->setQueryCacheImpl(new ArrayCache());
        $config->setProxyDir(__DIR__.'/Proxies');
        $config->setAutoGenerateProxyClasses(ProxyFactory::AUTOGENERATE_EVAL);
        $config->setProxyNamespace('DH\DoctrineAuditBundle\Tests\Proxies');
        $config->setMetadataDriverImpl($config->newDefaultAnnotationDriver([__DIR__.'/Fixtures'], false));

        return EntityManager::create($connection, $config);
    }

    protected function getSecondaryConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    protected function getReader(?AuditConfiguration $configuration = null): AuditReader
    {
        return new AuditReader($configuration ?? $this->createAuditConfiguration(), $this->getEntityManager());
    }
}
