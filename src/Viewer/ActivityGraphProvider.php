<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Provides activity graph data for audited entities.
 */
class ActivityGraphProvider
{
    public const string CACHE_TAG = 'dh_auditor.activity';

    private const string CACHE_KEY_PREFIX = 'dh_auditor.activity.';

    public function __construct(
        private readonly int $days,
        private readonly string $layout,
        private readonly bool $cacheEnabled,
        private readonly int $cacheTtl,
        private readonly ?CacheItemPoolInterface $cache = null,
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
     * @return array{normalized: array<int>, raw: array<int>} Normalized (0-100) and raw (event counts) arrays
     */
    public function getActivityDataWithRaw(string $entity, Reader $reader): array
    {
        $cacheKey = $this->getCacheKey($entity);

        // Try to get from cache
        if ($this->cacheEnabled && $this->cache instanceof CacheItemPoolInterface) {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                /** @var array{normalized: array<int>, raw: array<int>} $cachedData */
                $cachedData = $item->get();

                return $cachedData;
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
     * Clear the activity graph cache.
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
            return $this->cache->deleteItem($this->getCacheKey($entity));
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
     * Get the number of days configured for the activity graph.
     */
    public function getDays(): int
    {
        return $this->days;
    }

    /**
     * Get the layout configured for the activity graph.
     *
     * @return string 'bottom' or 'inline'
     */
    public function getLayout(): string
    {
        return $this->layout;
    }

    /**
     * Get the cache key for an entity.
     */
    private function getCacheKey(string $entity): string
    {
        return self::CACHE_KEY_PREFIX.md5($entity);
    }

    /**
     * Compute activity data from the database.
     *
     * @return array<int> Array of N values (0-100) representing normalized activity
     */
    /**
     * Compute activity data from the database.
     *
     * @return array{normalized: array<int>, raw: array<int>} Normalized (0-100) and raw (event counts) arrays
     */
    private function computeActivityData(string $entity, Reader $reader): array
    {
        $storageService = $reader->getProvider()->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();
        $auditTable = $reader->getEntityAuditTableName($entity);

        $minDate = new \DateTimeImmutable(\sprintf('-%d days', $this->days));

        $sql = \sprintf(
            'SELECT DATE(created_at) as day, COUNT(*) as cnt FROM %s WHERE created_at >= :minDate GROUP BY DATE(created_at) ORDER BY day ASC',
            $auditTable
        );

        $result = $connection->executeQuery($sql, ['minDate' => $minDate->format('Y-m-d')])->fetchAllAssociative();

        // Build array indexed by date
        /** @var array<string, int> $countsByDate */
        $countsByDate = [];
        foreach ($result as $row) {
            /** @var string $day */
            $day = $row['day'];

            /** @var int|string $cnt */
            $cnt = $row['cnt'];
            $countsByDate[$day] = (int) $cnt;
        }

        // Generate raw values for the last N days
        $raw = [];
        $max = 0;
        for ($i = $this->days - 1; $i >= 0; --$i) {
            $date = new \DateTimeImmutable(\sprintf('-%d days', $i))->format('Y-m-d');
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
        ];
    }
}
