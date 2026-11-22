<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Benchmarks;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration as AuditorConfiguration;
use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\AuditorBundle\Controller\ViewerController;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PhpBench\Attributes as Bench;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Benchmarks the filter options of the entity stream (types, users, anonymous entries, changed
 * fields) on a large audit table, through ViewerController::getFilterOptions().
 *
 * The audit table is created by the SchemaManager of the installed provider, then seeded once per
 * subject with BENCH_ROWS rows written by BENCH_USERS users, each from several IPs.
 *
 * Needs a real database server: an in-memory SQLite database has no query planner worth measuring.
 *
 *   BENCH_DATABASE_URL="pdo-mysql://root:root@127.0.0.1:3306/auditor" composer bench -- --tag=before --store
 *   BENCH_DATABASE_URL="pdo-mysql://root:root@127.0.0.1:3306/auditor" composer bench -- --ref=before
 */
#[Bench\Iterations(10)]
#[Bench\Revs(1)]
#[Bench\Warmup(1)]
#[Bench\BeforeClassMethods(['seed'])]
#[Bench\BeforeMethods(['setUp'])]
#[Bench\AfterMethods(['tearDown'])]
class UserFilterBench
{
    private const string AUDIT_TABLE = 'author_audit';

    private Connection $connection;

    private Reader $reader;

    private \ReflectionMethod $getFilterOptions;

    private ViewerController $controller;

    public function setUp(): void
    {
        $this->connection = self::createConnection();
        $this->reader = new Reader(self::createProvider($this->connection));
        $this->controller = new ViewerController(new Environment(new ArrayLoader()));
        $this->getFilterOptions = new \ReflectionMethod(ViewerController::class, 'getFilterOptions');
    }

    public function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * Filter options of the entity stream of all authors.
     */
    public function benchEntityFilterOptions(): void
    {
        $this->getFilterOptions->invoke($this->controller, $this->reader, Author::class, null);
    }

    /**
     * Filter options of the entity stream of one author.
     */
    public function benchObjectFilterOptions(): void
    {
        $this->getFilterOptions->invoke($this->controller, $this->reader, Author::class, '42');
    }

    public static function seed(): void
    {
        $connection = self::createConnection();
        $schemaManager = $connection->createSchemaManager();
        foreach ([self::AUDIT_TABLE, 'author'] as $table) {
            if ($schemaManager->tablesExist([$table])) {
                $connection->executeStatement('DROP TABLE '.$table);
            }
        }

        $provider = self::createProvider($connection);
        $entityManager = $provider->getStorageServiceForEntity(Author::class)->getEntityManager();
        new SchemaTool($entityManager)->createSchema([$entityManager->getClassMetadata(Author::class)]);
        new SchemaManager($provider)->updateAuditSchema();

        $connection->transactional(static function (Connection $connection): void {
            self::insertRows($connection);
        });
        $connection->executeStatement('ANALYZE TABLE '.self::AUDIT_TABLE);
        $connection->close();
    }

    /**
     * Inserts BENCH_ROWS audit rows by batches of 1,000: BENCH_USERS users (one row in ten is
     * anonymous), each from 4 IPs, with a realistic diff payload.
     */
    private static function insertRows(Connection $connection): void
    {
        $count = max(1, (int) (getenv('BENCH_ROWS') ?: 500_000));
        $users = max(1, (int) (getenv('BENCH_USERS') ?: 50));
        $diffs = json_encode([
            'source' => ['id' => 1, 'class' => Author::class, 'label' => 'Author #1', 'table' => 'author'],
            'changes' => [
                'fullname' => ['old' => 'John Doe', 'new' => 'Jane Doe'],
                'email' => ['old' => 'john.doe@example.com', 'new' => 'jane.doe@example.com'],
                'bio' => ['old' => str_repeat('Lorem ipsum dolor sit amet. ', 4), 'new' => str_repeat('Consectetur adipiscing elit. ', 4)],
            ],
        ], JSON_THROW_ON_ERROR);

        $columns = '(schema_version, type, object_id, discriminator, transaction_id, diffs, extra_data, blame_id, blame, created_at)';
        for ($offset = 0; $offset < $count; $offset += 1_000) {
            $values = [];
            $params = [];
            for ($i = $offset; $i < min($count, $offset + 1_000); ++$i) {
                $user = 0 === $i % 10 ? null : ($i + intdiv($i, 10)) % $users;
                $values[] = '(2, ?, ?, NULL, ?, ?, NULL, ?, ?, ?)';
                array_push(
                    $params,
                    ['insert', 'update', 'remove'][$i % 3],
                    (string) intdiv($i, 5),
                    new Ulid()->toBase32(),
                    $diffs,
                    null === $user ? null : (string) $user,
                    json_encode(null === $user ? ['username' => null, 'user_fqdn' => null, 'user_firewall' => null, 'ip' => '10.1.0.'.($i % 4)] : [
                        'username' => 'user'.$user,
                        'user_fqdn' => 'App\Entity\User',
                        'user_firewall' => 'main',
                        'ip' => '10.0.'.$user.'.'.($i % 4),
                    ], JSON_THROW_ON_ERROR),
                    date('Y-m-d H:i:s', 1_700_000_000 + $i),
                );
            }

            $connection->executeStatement('INSERT INTO '.self::AUDIT_TABLE.' '.$columns.' VALUES '.implode(', ', $values), $params);
        }
    }

    private static function createConnection(): Connection
    {
        $databaseUrl = getenv('BENCH_DATABASE_URL');
        if (!\is_string($databaseUrl) || '' === $databaseUrl) {
            throw new \RuntimeException('UserFilterBench needs BENCH_DATABASE_URL (e.g. "pdo-mysql://root:root@127.0.0.1:3306/auditor").');
        }

        $dbalConfig = new DBALConfiguration();
        $dbalConfig->setMiddlewares([new AuditorMiddleware()]);

        return DriverManager::getConnection(new DsnParser()->parse($databaseUrl), $dbalConfig);
    }

    private static function createProvider(Connection $connection): DoctrineProvider
    {
        $auditor = new Auditor(new AuditorConfiguration(['timezone' => 'UTC', 'enabled' => true]), new EventDispatcher());
        $provider = new DoctrineProvider(new Configuration([
            'table_prefix' => '',
            'table_suffix' => '_audit',
            'ignored_columns' => [],
            'entities' => [Author::class => ['enabled' => true]],
        ]));
        $auditor->registerProvider($provider);

        $ormConfig = ORMSetup::createAttributeMetadataConfiguration([
            __DIR__.'/../vendor/damienharper/auditor-doctrine-provider/tests/Provider/Doctrine/Fixtures/Entity/Standard/Blog',
        ], isDevMode: true);
        $ormConfig->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $ormConfig->enableNativeLazyObjects(true);
        $entityManager = new EntityManager($connection, $ormConfig);

        $provider->registerStorageService(new StorageService('default', $entityManager));
        $provider->registerAuditingService(new AuditingService('default', $entityManager));

        return $provider;
    }
}
