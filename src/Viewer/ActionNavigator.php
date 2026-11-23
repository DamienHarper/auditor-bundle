<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\Model\Entry;
use DH\AuditorBundle\Tests\Viewer\ActionNavigatorTest;

/**
 * Finds the actions just before and just after one action in a history, for the previous / next links of the action
 * page opened from the history of an object.
 *
 * @see ActionNavigatorTest
 */
final class ActionNavigator
{
    /**
     * Entries read at most to find the action: beyond, no link.
     */
    public const int MAX_ENTRIES = 5_000;

    /**
     * @param iterable<Entry> $entries the history, most recent first
     *
     * @return array{newer: ?string, older: ?string} transaction ids of the neighbouring actions
     */
    public static function neighbours(iterable $entries, string $transactionId): array
    {
        $newer = null;
        $current = null;
        $found = false;
        $read = 0;

        foreach ($entries as $entry) {
            if (++$read > self::MAX_ENTRIES) {
                break;
            }

            $key = HistoryGrouper::transactionKey($entry);
            if ($key === $current) {
                continue;
            }

            if ($found) {
                return ['newer' => $newer, 'older' => $key];
            }

            if ($key === $transactionId) {
                $found = true;
            } else {
                $newer = $key;
            }

            $current = $key;
        }

        return ['newer' => $found ? $newer : null, 'older' => null];
    }
}
