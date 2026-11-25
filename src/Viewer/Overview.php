<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Attribute\Security;
use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Provider\Doctrine\Configuration;
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
     * The most recent actions across the audited entities: the latest entries of each table give the latest actions,
     * which are then read whole (WholeActions::MAX_ENTRIES at most per table) across the tables.
     *
     * @param list<string> $entities
     *
     * @return list<HistoryItem>
     */
    public function recentActivity(array $entities, int $limit): array
    {
        $queries = [];
        $rows = [];
        foreach ($entities as $entity) {
            try {
                $query = $this->reader->createQuery($entity, ['page_size' => null]);
            } catch (AccessDeniedException) {
                continue;
            }

            $queries[$entity] = $query;
            foreach ((clone $query)->limit(self::RECENT_ENTRIES_PER_TABLE)->execute() as $entry) {
                $rows[] = ['entity' => $entity, 'entry' => $entry];
            }
        }

        usort($rows, WholeActions::compare(...));

        // the latest actions; an entry without transaction id is an action of its own, already whole
        $latest = \array_slice(HistoryGrouper::byTransaction(array_column($rows, 'entry')), 0, $limit);
        $keys = array_fill_keys(array_map(static fn (HistoryItem $item): string => $item->key, $latest), true);
        $transactions = array_values(array_filter(array_map(static fn (HistoryItem $item): ?string => $item->entries[0]->transactionId, $latest), static fn (?string $id): bool => null !== $id));
        $entries = array_values(array_filter($rows, static fn (array $row): bool => null === $row['entry']->transactionId && isset($keys[HistoryGrouper::transactionKey($row['entry'])])));

        // then the other ones read whole, across the tables (on the transaction_id index), WholeActions::MAX_ENTRIES at
        // most per table: the entries read above keep an action that a larger one leaves out
        if ([] !== $transactions) {
            $read = [];
            foreach ($rows as $row) {
                if (null !== $row['entry']->transactionId && isset($keys[HistoryGrouper::transactionKey($row['entry'])])) {
                    $read[$row['entity'].'#'.$row['entry']->id] = $row;
                }
            }

            foreach ($queries as $entity => $query) {
                foreach ($query->transactionEntries($transactions, WholeActions::MAX_ENTRIES) as $entry) {
                    $read[$entity.'#'.$entry->id] ??= ['entity' => $entity, 'entry' => $entry];
                }
            }

            $entries = [...$entries, ...array_values($read)];
            usort($entries, WholeActions::compare(...));
        }

        return \array_slice(HistoryGrouper::byTransaction(array_column($entries, 'entry')), 0, $limit);
    }

    /**
     * Number of actions of an entity, counted as its entity stream counts them. Cached with the activity data when the
     * cache is on, unless a read scope makes the count depend on the reader.
     */
    public function actionCount(string $entity): int
    {
        $count = fn (): int => $this->reader->createQuery($entity, ['page_size' => null])->countTransactions();

        /** @var Configuration $configuration */
        $configuration = $this->reader->getProvider()->getConfiguration();
        if (!$this->cache instanceof ActivityGraphProvider || null !== $configuration->getReadScope()) {
            return $count();
        }

        return $this->cache->getActionCount($entity, $count);
    }
}
