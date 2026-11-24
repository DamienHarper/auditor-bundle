<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\AuditorBundle\Tests\Controller\ViewerControllerTest;

/**
 * Completes the actions of a page of entries, so that a list never tells part of an action: a page cut by a number of
 * entries holds the end of an action begun on the previous page, or the start of one that goes on in the next.
 *
 * An action belongs to the page of its most recent entry: its other entries are read again (with the filters of the
 * page), and an action whose most recent entry comes before the page is left out.
 *
 * @see ViewerControllerTest
 */
final class WholeActions
{
    /**
     * Entries read at most to complete the actions of a page: beyond, an action tells only the entries read.
     */
    public const int MAX_ENTRIES = 1_000;

    /**
     * @param array<string, Query>                      $queries queries the page was read from, by entity
     * @param list<array{entity: string, entry: Entry}> $page    entries of the page, in the order of compare()
     *
     * @return list<array{entity: string, entry: Entry}> the entries of the actions of the page, in the order of compare()
     */
    public static function complete(array $queries, array $page): array
    {
        $first = $page[0] ?? null;
        $transactions = array_values(array_unique(array_filter(array_map(static fn (array $row): ?string => $row['entry']->transactionId, $page), static fn (?string $id): bool => null !== $id && '' !== $id)));
        if (null === $first || [] === $transactions) {
            return $page;
        }

        $rows = [];
        foreach ($page as $row) {
            $rows[$row['entity'].'#'.$row['entry']->id] = $row;
        }

        foreach ($queries as $entity => $query) {
            foreach (self::read($query, $transactions) as $entry) {
                $rows[$entity.'#'.$entry->id] ??= ['entity' => $entity, 'entry' => $entry];
            }
        }

        $rows = array_values($rows);
        usort($rows, self::compare(...));

        // an action shown on a previous page: one of its entries comes before the first one of this page
        $before = [];
        foreach ($rows as $row) {
            if (self::compare($row, $first) >= 0) {
                break;
            }

            if (null !== $row['entry']->transactionId) {
                $before[$row['entry']->transactionId] = true;
            }
        }

        return array_values(array_filter($rows, static fn (array $row): bool => !isset($before[(string) $row['entry']->transactionId])));
    }

    /**
     * Order of the lists of actions: most recent first; entries written at the same instant by id (newest first), then
     * by entity, so that a list never depends on the order in which the entities were read.
     *
     * @param array{entity: string, entry: Entry} $a
     * @param array{entity: string, entry: Entry} $b
     */
    public static function compare(array $a, array $b): int
    {
        return [$b['entry']->createdAt, $b['entry']->id, $a['entity']] <=> [$a['entry']->createdAt, $a['entry']->id, $b['entity']];
    }

    /**
     * Entries of the given transactions read by $query (its filters kept), most recent first.
     *
     * @param non-empty-list<string> $transactions
     *
     * @return list<Entry>
     */
    private static function read(Query $query, array $transactions): array
    {
        // a table not migrated to schema v2 keeps its transaction ids in transaction_hash
        $column = \in_array(Query::TRANSACTION_ID, SchemaHelper::getTableColumnNames($query->getConnection(), $query->getTable()), true)
            ? Query::TRANSACTION_ID
            : Query::LEGACY_TRANSACTION_HASH;

        return array_values((clone $query)
            ->addFilter(new SimpleFilter($column, $transactions))
            ->limit(self::MAX_ENTRIES)
            ->execute());
    }
}
