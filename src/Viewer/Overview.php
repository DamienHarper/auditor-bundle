<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Attribute\Security;
use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\AuditorBundle\Tests\Controller\ViewerControllerTest;

/**
 * Data of the home page of the viewer: the audited entities the reader may view, their number of actions, and the
 * most recent actions across all of them.
 *
 * @see ViewerControllerTest
 */
final readonly class Overview
{
    /**
     * Entries read per audit table to build the recent activity.
     */
    public const int RECENT_ENTRIES_PER_TABLE = 20;

    public function __construct(
        private Reader $reader,
        private ?ActivityGraphProvider $cache = null,
    ) {}

    /**
     * Audit tables of the entities the reader may view.
     *
     * @return array<string, string> entity FQCN => audit table
     */
    public function entities(): array
    {
        $schemaManager = new SchemaManager($this->reader->getProvider());
        $roleChecker = $this->reader->getProvider()->getAuditor()->getConfiguration()->getRoleChecker();

        /** @var AuditingService[] $auditingServices */
        $auditingServices = $this->reader->getProvider()->getAuditingServices();

        $entities = [];
        foreach ($auditingServices as $auditingService) {
            foreach ($schemaManager->getAuditableTableNames($auditingService->getEntityManager()) as $entity => $table) {
                if (\is_string($table) && (null === $roleChecker || (bool) $roleChecker((string) $entity, Security::VIEW_SCOPE))) {
                    $entities[(string) $entity] = $table;
                }
            }
        }

        return $entities;
    }

    /**
     * The most recent actions across the audited entities: the latest entries of each table, grouped by transaction.
     * An action whose entries are spread over many tables may show only part of them.
     *
     * @param list<string> $entities
     *
     * @return list<HistoryItem>
     */
    public function recentActivity(array $entities, int $limit): array
    {
        $entries = [];
        foreach ($entities as $entity) {
            try {
                $latest = $this->reader->createQuery($entity, ['page_size' => self::RECENT_ENTRIES_PER_TABLE])->execute();
            } catch (AccessDeniedException) {
                continue;
            }

            foreach ($latest as $entry) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn (Entry $a, Entry $b): int => [$b->createdAt, $b->transactionId, $b->id] <=> [$a->createdAt, $a->transactionId, $a->id]);

        return \array_slice(HistoryGrouper::byTransaction($entries), 0, $limit);
    }

    /**
     * Number of actions (distinct transactions) of an entity, cached with the activity data when the cache is on.
     */
    public function actionCount(string $entity): int
    {
        $count = function () use ($entity): int {
            $connection = $this->reader->getProvider()->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
            $table = $this->reader->getEntityAuditTableName($entity);
            $column = \in_array('transaction_id', SchemaHelper::getTableColumnNames($connection, $table), true) ? 'transaction_id' : 'transaction_hash';

            $value = $connection->fetchOne(\sprintf('SELECT COUNT(DISTINCT %s) FROM %s', $column, $table));

            return is_numeric($value) ? (int) $value : 0;
        };

        return $this->cache instanceof ActivityGraphProvider ? $this->cache->getActionCount($entity, $count) : $count();
    }
}
