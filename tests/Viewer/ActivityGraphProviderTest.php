<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Small]
#[CoversClass(ActivityGraphProvider::class)]
final class ActivityGraphProviderTest extends WebTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    private DoctrineProvider $provider;

    #[\Override]
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
    public function itReturnsArrayWithCorrectLength(): void
    {
        $provider = new ActivityGraphProvider(7, 'bottom', false, 300);
        $reader = $this->createReader();

        $result = $provider->getActivityData('DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Post', $reader);

        self::assertCount(7, $result);
    }

    #[Test]
    public function itReturnsZerosWhenNoActivity(): void
    {
        $provider = new ActivityGraphProvider(7, 'bottom', false, 300);
        $reader = $this->createReader();

        $result = $provider->getActivityData('DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Post', $reader);

        // Fresh database should have no activity
        self::assertSame([0, 0, 0, 0, 0, 0, 0], $result);
    }

    #[Test]
    public function itUsesCacheWhenAvailableAndHit(): void
    {
        $cachedData = [10, 20, 30, 40, 50, 60, 70];

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn($cachedData);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())
            ->method('getItem')
            ->willReturn($cacheItem);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);
        $reader = $this->createReader();

        $result = $provider->getActivityData('DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Post', $reader);

        self::assertSame($cachedData, $result);
    }

    #[Test]
    public function itStoresInCacheOnMiss(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects(self::once())->method('set');
        $cacheItem->expects(self::once())->method('expiresAfter')->with(300);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);
        $cache->expects(self::once())->method('save')->with($cacheItem);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);
        $reader = $this->createReader();

        $provider->getActivityData('DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Post', $reader);
    }

    #[Test]
    public function itAppliesTagsWithTagAwareCache(): void
    {
        // This test verifies that when a TagAwareAdapterInterface is used,
        // the provider correctly identifies it for tag support.
        // The actual tagging is tested via clearCacheAllUsesTagsWhenAvailable.

        $cache = $this->createMock(TagAwareAdapterInterface::class);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        // Verify the cache is available
        self::assertTrue($provider->isCacheAvailable());

        // Verify clearCache with tags works (this proves tag support detection)
        $cache->expects(self::once())
            ->method('invalidateTags')
            ->with([ActivityGraphProvider::CACHE_TAG])
            ->willReturn(true);

        self::assertTrue($provider->clearCache());
    }

    #[Test]
    public function itDoesNotUseCacheWhenDisabled(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('getItem');

        $provider = new ActivityGraphProvider(7, 'bottom', false, 300, $cache);
        $reader = $this->createReader();

        $provider->getActivityData('DH\\Auditor\\Tests\\Provider\\Doctrine\\Fixtures\\Entity\\Standard\\Blog\\Post', $reader);
    }

    #[Test]
    public function clearCacheReturnsFalseWithoutCache(): void
    {
        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, null);

        self::assertFalse($provider->clearCache());
        self::assertFalse($provider->clearCache('App\\Entity\\User'));
    }

    #[Test]
    public function clearCacheForEntityDeletesItem(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())
            ->method('deleteItem')
            ->willReturn(true);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        self::assertTrue($provider->clearCache('App\\Entity\\User'));
    }

    #[Test]
    public function clearCacheAllUsesTagsWhenAvailable(): void
    {
        $cache = $this->createMock(TagAwareAdapterInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTags')
            ->with([ActivityGraphProvider::CACHE_TAG])
            ->willReturn(true);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        self::assertTrue($provider->clearCache());
    }

    #[Test]
    public function clearCacheAllReturnsFalseWithoutTagSupport(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);

        $provider = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);

        self::assertFalse($provider->clearCache());
    }

    #[Test]
    public function isCacheAvailableReturnsCorrectValue(): void
    {
        $cache = $this->createStub(CacheItemPoolInterface::class);

        $providerWithCache = new ActivityGraphProvider(7, 'bottom', true, 300, $cache);
        $providerWithoutCache = new ActivityGraphProvider(7, 'bottom', true, 300, null);
        $providerDisabled = new ActivityGraphProvider(7, 'bottom', false, 300, $cache);

        self::assertTrue($providerWithCache->isCacheAvailable());
        self::assertFalse($providerWithoutCache->isCacheAvailable());
        self::assertFalse($providerDisabled->isCacheAvailable());
    }

    #[Test]
    public function getDaysReturnsConfiguredValue(): void
    {
        $provider = new ActivityGraphProvider(14, 'bottom', false, 300);

        self::assertSame(14, $provider->getDays());
    }
}
