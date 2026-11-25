<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Provides activity graph data for audited entities.
 */
final readonly class ActivityGraphProvider
{
    public const string CACHE_TAG = 'dh_auditor.activity';

    private const string CACHE_KEY_PREFIX = 'dh_auditor.activity.v4.';

    private const string ACTION_COUNT_CACHE_KEY_PREFIX = 'dh_auditor.actions.v2.';

    public function __construct(
        public private(set) int $days,
        public private(set) string $layout,
        private bool $cacheEnabled,
        private int $cacheTtl,
        private ?CacheItemPoolInterface $cache = null,
    ) {}

    /**
     * Get activity data for an entity.
     *
     * @return array<int> Array of N values (0-100) representing normalized activity
     */
    public function getActivityData(string $entity, Reader $reader): array
    {
        return $this->getActivityDataWithRaw($entity, $reader)['normalized'];
    }

    /**
     * Get activity data for an entity with both normalized and raw values.
     *
     * @return array{normalized: array<int>, raw: array<int>, dates: list<string>} Values and their calendar dates
     */
    public function getActivityDataWithRaw(string $entity, Reader $reader): array
    {
        /** @var Configuration $configuration */
        $configuration = $reader->getProvider()->getConfiguration();
        if (null !== $configuration->getReadScope()) {
            // a read scope depends on the reader (a tenant…): its counts are not shared through the cache
            return $this->computeActivityData($entity, $reader);
        }

        $cacheKey = $this->getCacheKey($entity);

        // Try to get from cache
        if ($this->cacheEnabled && $this->cache instanceof CacheItemPoolInterface) {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();    // @phpstan-ignore-line
            }
        }

        // Compute the data
        $data = $this->computeActivityData($entity, $reader);

        // Store in cache
        if ($this->cacheEnabled && $this->cache instanceof CacheItemPoolInterface) {
            $item = $this->cache->getItem($cacheKey);
            $item->set($data);
            $item->expiresAfter($this->cacheTtl);

            if ($item instanceof ItemInterface && $this->cache instanceof TagAwareAdapterInterface) {
                $item->tag([self::CACHE_TAG]);
            }

            $this->cache->save($item);
        }

        return $data;
    }

    /**
     * Number of actions of an entity, as its entity stream counts them, kept in the activity cache (same lifetime and
     * tag) when it is enabled.
     *
     * @param \Closure(): int $count
     */
    public function getActionCount(string $entity, \Closure $count): int
    {
        return $this->remember(self::ACTION_COUNT_CACHE_KEY_PREFIX.md5($entity), $count, \is_int(...));
    }

    /**
     * Computes a value, or reads it from the activity cache (same lifetime and tag) when it is enabled. A cached value
     * that $valid rejects (written by another version, another shape) is computed again.
     *
     * @template T
     *
     * @param \Closure(): T              $compute
     * @param null|\Closure(mixed): bool $valid
     *
     * @return T
     */
    public function remember(string $key, \Closure $compute, ?\Closure $valid = null): mixed
    {
        if (!$this->cacheEnabled || !$this->cache instanceof CacheItemPoolInterface) {
            return $compute();
        }

        $item = $this->cache->getItem($key);
        if ($item->isHit() && (!$valid instanceof \Closure || $valid($item->get()))) {
            return $item->get();
        }

        $value = $compute();
        $item->set($value);
        $item->expiresAfter($this->cacheTtl);
        if ($item instanceof ItemInterface && $this->cache instanceof TagAwareAdapterInterface) {
            $item->tag([self::CACHE_TAG]);
        }

        $this->cache->save($item);

        return $value;
    }

    /**
     * Clear the activity graph cache.
     *
     * The authors of the histories (ObjectHistory::authors()) are only cleared with the whole cache (tag-aware pool).
     *
     * @param null|string $entity Clear cache for a specific entity, or all if null
     *
     * @return bool True if cache was cleared successfully
     */
    public function clearCache(?string $entity = null): bool
    {
        if (!$this->cache instanceof CacheItemPoolInterface) {
            return false;
        }

        if (null !== $entity) {
            return $this->cache->deleteItems([$this->getCacheKey($entity), self::ACTION_COUNT_CACHE_KEY_PREFIX.md5($entity)]);
        }

        if ($this->cache instanceof TagAwareAdapterInterface) {
            return $this->cache->invalidateTags([self::CACHE_TAG]);
        }

        // Cannot clear all without tags support
        return false;
    }

    /**
     * Check if cache is available and enabled.
     */
    public function isCacheAvailable(): bool
    {
        return $this->cacheEnabled && $this->cache instanceof CacheItemPoolInterface;
    }

    /**
     * Get the cache key for an entity.
     */
    private function getCacheKey(string $entity): string
    {
        return self::CACHE_KEY_PREFIX.md5($entity);
    }

    /**
     * Compute activity data from the database: the number of actions (transactions) of each day, as the entity stream
     * of that day counts them (its entity alone in a single-table inheritance, in the read scope when there is one).
     *
     * @return array{normalized: array<int>, raw: array<int>, dates: list<string>} Values and their calendar dates
     */
    private function computeActivityData(string $entity, Reader $reader): array
    {
        $timezone = $reader->getProvider()->getAuditor()->getConfiguration()->timezone;
        $today = new \DateTimeImmutable('today', new \DateTimeZone($timezone));
        $minDate = $today->modify(\sprintf('-%d days', $this->days - 1));

        $countsByDate = $reader->createQuery($entity, ['page_size' => null])
            ->addFilter(new DateRangeFilter(Query::CREATED_AT, $minDate))
            ->countTransactionsByDay()
        ;

        // Generate raw values for the last N days
        $raw = [];
        $dates = [];
        $max = 0;
        for ($i = $this->days - 1; $i >= 0; --$i) {
            $date = $today->modify(\sprintf('-%d days', $i))->format('Y-m-d');
            $dates[] = $date;
            $count = $countsByDate[$date] ?? 0;
            $raw[] = $count;
            $max = max($max, $count);
        }

        // Normalize to 0-100
        $normalized = $raw;
        if ($max > 0) {
            $normalized = array_map(static fn (int $v): int => (int) round(($v / $max) * 100), $raw);
        }

        return [
            'normalized' => $normalized,
            'raw' => $raw,
            'dates' => $dates,
        ];
    }
}
