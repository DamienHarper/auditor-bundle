<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * @internal
 */
#[Small]
#[CoversClass(ActivityGraphProvider::class)]
final class ActivityGraphProviderTest extends WebTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    protected function setUp(): void
    {
        parent::setUp();
        self::createClient();
        // provider with 1 em for both storage and auditing
        $this->createAndInitDoctrineProvider();
        // declare audited entities
        $this->configureEntities();
        // setup entity and audit schemas
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
    }

    #[Test]
    public function testReturnsArrayWithCorrectLength(): void
    {
        $provider = new ActivityGraphProvider(7, 'bottom', false, 300);
        $reader = $this->createReader();

        $result = $provider->getActivityData(Post::class, $reader);

        $this->assertCount(7, $result);
    }

    #[Test]
    public function testReturnsZerosWhenNoActivity(): void
    {
        $provider = new ActivityGraphProvider(7, 'bottom', false, 300);
        $reader = $this->createReader();

        $result = $provider->getActivityData(Post::class, $reader);

        // Fresh database should have no activity
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $result);
    }

    #[Test]
    public function testUsesCacheWhenAvailableAndHit(): void
    {
        $normalizedData = [10, 20, 30, 40, 50, 60, 70];
        $rawData = [1, 2, 3, 4, 5, 6, 7];
        $dates = ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05', '2026-01-06', '2026-01-07'];
        $cachedData = ['normalized' => $normalizedData, 'raw' => $rawData, 'dates' => $dates];

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($cachedData);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())
            ->method('getItem')
            ->willReturn($cacheItem)
        ;

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);
        $reader = $this->createReader();

        $result = $provider->getActivityDataWithRaw(Post::class, $reader);

        $this->assertSame($cachedData, $result);
    }

    #[Test]
    public function testStoresInCacheOnMiss(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects($this->once())->method('set');
        $cacheItem->expects($this->once())->method('expiresAfter')->with(300);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);
        $cache->expects($this->once())->method('save')->with($cacheItem);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);
        $reader = $this->createReader();

        $provider->getActivityData(Post::class, $reader);
    }

    #[Test]
    public function testAppliesTagsWithTagAwareCache(): void
    {
        // This test verifies that when a TagAwareAdapterInterface is used,
        // the provider correctly identifies it for tag support.
        // The actual tagging is tested via clearCacheAllUsesTagsWhenAvailable.

        $cache = $this->createMock(TagAwareAdapterInterface::class);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        // Verify the cache is available
        $this->assertTrue($provider->isCacheAvailable());

        // Verify clearCache with tags works (this proves tag support detection)
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with([ActivityGraphProvider::CACHE_TAG])
            ->willReturn(true)
        ;

        $this->assertTrue($provider->clearCache());
    }

    #[Test]
    public function testDoesNotUseCacheWhenDisabled(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->never())->method('getItem');

        $provider = new ActivityGraphProvider(7, 'bottom', false, 300, $cache);
        $reader = $this->createReader();

        $provider->getActivityData(Post::class, $reader);
    }

    #[Test]
    public function testClearCacheReturnsFalseWithoutCache(): void
    {
        $provider = new ActivityGraphProvider(7, 'bottom', true, 300);

        $this->assertFalse($provider->clearCache());
        $this->assertFalse($provider->clearCache('App\Entity\User'));
    }

    #[Test]
    public function testClearCacheForEntityDeletesItem(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())
            ->method('deleteItems')
            ->willReturn(true)
        ;

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        $this->assertTrue($provider->clearCache('App\Entity\User'));
    }

    #[Test]
    public function testClearCacheForEntityDeletesTheActivityAndTheCounts(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->once())
            ->method('deleteItems')
            ->willReturnCallback(function (array $keys): true {
                $this->assertCount(3, $keys);
                $this->assertStringStartsWith('dh_auditor.activity.', $keys[0]);
                $this->assertStringStartsWith('dh_auditor.count.', $keys[1]);
                $this->assertStringStartsWith('dh_auditor.actions.', $keys[2]);

                return true;
            })
        ;

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        $this->assertTrue($provider->clearCache('App\Entity\User'));
    }

    #[Test]
    public function testACachedValueOfAnotherShapeIsComputedAgain(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('key');
        $cache->save($item->set('not a count'));
        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        $this->assertSame(3, $provider->remember('key', static fn (): int => 3, \is_int(...)));
        $this->assertSame(3, $cache->getItem('key')->get(), 'And stored again.');
    }

    #[Test]
    public function testActionCountIsCached(): void
    {
        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, new ArrayAdapter());
        $calls = 0;
        $count = static function () use (&$calls): int {
            return 10 + ++$calls;
        };

        $this->assertSame(11, $provider->getActionCount('App\Entity\User', $count));
        $this->assertSame(11, $provider->getActionCount('App\Entity\User', $count), 'Read from the cache.');
        $this->assertSame(12, new ActivityGraphProvider(7, 'bottom', false, 300, new ArrayAdapter())->getActionCount('App\Entity\User', $count), 'Computed when the cache is disabled.');
    }

    #[Test]
    public function testEventCountIsCached(): void
    {
        $cache = new ArrayAdapter();
        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);
        $reader = $this->createReader();

        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post();
        $post->setTitle('Counted')->setBody('body')->setCreatedAt(new \DateTimeImmutable());
        $entityManager->persist($post);
        $entityManager->flush();

        $count = $reader->createQuery(Post::class)->count();
        $this->assertSame(1, $count);
        $this->assertSame($count, $provider->getEventCount(Post::class, $reader->createQuery(Post::class)));

        // deleted entries are still counted until the cache expires or is cleared
        $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM '.$reader->getEntityAuditTableName(Post::class)
        );
        $this->assertSame($count, $provider->getEventCount(Post::class, $reader->createQuery(Post::class)));

        $provider->clearCache(Post::class);
        $this->assertSame(0, $provider->getEventCount(Post::class, $reader->createQuery(Post::class)));
    }

    #[Test]
    public function testEventCountWithoutCacheCounts(): void
    {
        $provider = new ActivityGraphProvider(7, 'bottom', false, 300);
        $reader = $this->createReader();

        $this->assertSame($reader->createQuery(Post::class)->count(), $provider->getEventCount(Post::class, $reader->createQuery(Post::class)));
    }

    #[Test]
    public function testClearCacheAllUsesTagsWhenAvailable(): void
    {
        $cache = $this->createMock(TagAwareAdapterInterface::class);
        $cache->expects($this->once())
            ->method('invalidateTags')
            ->with([ActivityGraphProvider::CACHE_TAG])
            ->willReturn(true)
        ;

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        $this->assertTrue($provider->clearCache());
    }

    #[Test]
    public function testClearCacheAllReturnsFalseWithoutTagSupport(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        $this->assertFalse($provider->clearCache());
    }

    #[Test]
    public function testIsCacheAvailableReturnsCorrectValue(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);

        $providerWithCache = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);
        $providerWithoutCache = new ActivityGraphProvider(7, 'bottom', true, 300);
        $providerDisabled = new ActivityGraphProvider(7, 'bottom', false, 300, $cache);

        $this->assertTrue($providerWithCache->isCacheAvailable());
        $this->assertFalse($providerWithoutCache->isCacheAvailable());
        $this->assertFalse($providerDisabled->isCacheAvailable());
    }

    #[Test]
    public function testDaysPropertyReturnsConfiguredValue(): void
    {
        $provider = new ActivityGraphProvider(14, 'bottom', false, 300);

        $this->assertSame(14, $provider->days);
    }

    public function testCountsActionsNotEntries(): void
    {
        $this->setupEntities();
        $reader = $this->createReader();
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $table = $reader->getEntityAuditTableName(Post::class);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $connection->executeStatement('UPDATE '.$table.' SET created_at = :date', ['date' => $today->format('Y-m-d').' 12:00:00']);
        $query = $reader->createQuery(Post::class, ['page_size' => null]);
        $this->assertGreaterThan($query->countTransactions(), $query->count(), 'An action of the fixtures writes several entries.');

        $data = new ActivityGraphProvider(7, 'bottom', false, 300)->getActivityDataWithRaw(Post::class, $reader);

        $this->assertSame([0, 0, 0, 0, 0, 0, $query->countTransactions()], $data['raw']);
    }

    public function testAReadScopeCountsInItsScopeWithoutTheCache(): void
    {
        $this->setupEntities();
        $reader = $this->createReader();
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $table = $reader->getEntityAuditTableName(Post::class);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $connection->executeStatement('UPDATE '.$table.' SET created_at = :date', ['date' => $today->format('Y-m-d').' 12:00:00']);
        $all = $reader->createQuery(Post::class, ['page_size' => null])->countTransactions();
        $scoped = $reader->createQuery(Post::class, ['object_id' => '1', 'page_size' => null])->countTransactions();
        $this->assertGreaterThan($scoped, $all, 'The scope hides actions.');

        $graph = new ActivityGraphProvider(7, 'bottom', true, 300, new ArrayAdapter());
        $scope = static function (Query $query): void {
            $query->addFilter(new SimpleFilter(Query::OBJECT_ID, '1'));
        };

        $this->provider->getConfiguration()->setReadScope($scope);
        $this->assertSame($scoped, $graph->getActivityDataWithRaw(Post::class, $reader)['raw'][6], 'Counted in the scope.');

        $this->provider->getConfiguration()->setReadScope(null);
        $this->assertSame($all, $graph->getActivityDataWithRaw(Post::class, $reader)['raw'][6], 'The scoped count is not cached.');

        $this->provider->getConfiguration()->setReadScope($scope);
        $this->assertSame($scoped, $graph->getActivityDataWithRaw(Post::class, $reader)['raw'][6], 'The cached count is not read in a scope.');
    }

    public function testDatesMatchDailyCountsInTheAuditTimezone(): void
    {
        $this->setupEntities();
        $reader = $this->createReader();
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $table = $reader->getEntityAuditTableName(Post::class);
        $ids = $connection->fetchFirstColumn('SELECT id FROM '.$table.' ORDER BY id');
        $connection->executeStatement('UPDATE '.$table.' SET created_at = :date', ['date' => '2000-01-01 00:00:00']);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $firstDay = $today->modify('-6 days')->format('Y-m-d');
        $lastDay = $today->format('Y-m-d');
        $connection->update($table, ['created_at' => $firstDay.' 00:00:00'], ['id' => $ids[0]]);
        $connection->update($table, ['created_at' => $lastDay.' 23:59:59'], ['id' => $ids[1]]);

        $originalTimezone = date_default_timezone_get();
        $utcHour = (int) new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('H');
        date_default_timezone_set($utcHour < 12 ? 'Etc/GMT+12' : 'Pacific/Kiritimati');

        try {
            $provider = new ActivityGraphProvider(7, 'bottom', false, 300);
            $data = $provider->getActivityDataWithRaw(Post::class, $reader);
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        $this->assertSame([1, 0, 0, 0, 0, 0, 1], $data['raw']);
        $this->assertSame([100, 0, 0, 0, 0, 0, 100], $data['normalized']);
        $this->assertCount(7, $data['dates']);
        foreach ($data['dates'] as $index => $date) {
            $this->assertSame($today->modify('-'.(6 - $index).' days')->format('Y-m-d'), $date);
        }
    }
}
