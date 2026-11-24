<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\TransactionSummary;
use DH\AuditorBundle\Tests\Controller\ViewerControllerTest;

/**
 * Pages of whole actions: a list of actions is paginated by actions (transactions), never by entries, so that every page
 * but the last holds the same number of actions, each one told whole.
 *
 * @see ViewerControllerTest
 */
final class WholeActions
{
    /**
     * Entries read at most for a page (about, by audit table): the actions too large for their share tell only their most
     * recent entries, so that a page stays bounded and no action pushes the others out of it.
     */
    public const int MAX_ENTRIES = 1_000;

    /**
     * The entries of a page of actions read by one query per audit table (filters kept), in the order of compare().
     *
     * @param array<string, Query> $queries by entity
     *
     * @return list<array{entity: string, entry: Entry}>
     */
    public static function page(array $queries, int $limit, int $offset = 0): array
    {
        return self::read($queries, Query::transactionsOf(array_values($queries), $limit, $offset));
    }

    /**
     * The entries of $transactions read by one query per audit table (filters kept), in the order of compare().
     *
     * @param array<string, Query>     $queries      by entity
     * @param list<TransactionSummary> $transactions
     *
     * @return list<array{entity: string, entry: Entry}>
     */
    public static function read(array $queries, array $transactions): array
    {
        $share = self::share(array_map(static fn (TransactionSummary $transaction): int => $transaction->entries, $transactions));
        $rows = [];
        foreach ($queries as $entity => $query) {
            $entity = (string) $entity;
            $column = $query->getTransactionColumn();

            $small = [];
            foreach ($transactions as $transaction) {
                if (null === $transaction->transactionId) {
                    if ($transaction->table === $query->getTable() && null !== $transaction->entryId) {
                        array_push($rows, ...self::entries($entity, $query, Query::ID, [(string) $transaction->entryId], 1));
                    }
                } elseif ($transaction->entries > $share) {
                    // a large action: only its most recent entries, so that the page stays bounded
                    array_push($rows, ...self::entries($entity, $query, $column, [$transaction->transactionId], $share));
                } else {
                    $small[] = $transaction->transactionId;
                }
            }

            if ([] !== $small) {
                array_push($rows, ...self::entries($entity, $query, $column, $small, 0));
            }
        }

        usort($rows, self::compare(...));

        return $rows;
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
     * Entries an action may tell on a page of actions of $sizes entries: every action is told whole when the page holds
     * MAX_ENTRIES entries at most; otherwise the small actions are told whole and the large ones share what they leave.
     *
     * @param list<int> $sizes
     */
    private static function share(array $sizes): int
    {
        sort($sizes);
        $budget = self::MAX_ENTRIES;
        $left = \count($sizes);
        foreach ($sizes as $size) {
            if ($size * $left > $budget) {
                return max(1, intdiv($budget, $left));
            }

            $budget -= $size;
            --$left;
        }

        return self::MAX_ENTRIES;
    }

    /**
     * Entries read by $query (its filters kept) whose $column is one of $values, most recent first.
     *
     * @param non-empty-list<string> $values
     * @param int                    $limit  0 for no limit
     *
     * @return list<array{entity: string, entry: Entry}>
     */
    private static function entries(string $entity, Query $query, string $column, array $values, int $limit): array
    {
        $entries = (clone $query)
            ->addFilter(new SimpleFilter($column, $values))
            ->limit($limit)
            ->execute()
        ;

        return array_map(static fn (Entry $entry): array => ['entity' => $entity, 'entry' => $entry], array_values($entries));
    }
}
