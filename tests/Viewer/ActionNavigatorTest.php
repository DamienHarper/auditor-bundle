<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\Auditor\Model\Entry;
use DH\AuditorBundle\Viewer\ActionNavigator;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class ActionNavigatorTest extends TestCase
{
    public function testNeighbours(): void
    {
        $entries = [self::entry(6, 'T4'), self::entry(5, 'T3'), self::entry(4, 'T3'), self::entry(3, 'T2'), self::entry(2, 'T1'), self::entry(1, 'T1')];

        $this->assertSame(['newer' => 'T4', 'older' => 'T2', 'found' => true], ActionNavigator::neighbours($entries, 'T3'));
        $this->assertSame(['newer' => null, 'older' => 'T3', 'found' => true], ActionNavigator::neighbours($entries, 'T4'), 'The most recent action.');
        $this->assertSame(['newer' => 'T2', 'older' => null, 'found' => true], ActionNavigator::neighbours($entries, 'T1'), 'The oldest action.');
        $this->assertSame(['newer' => null, 'older' => null, 'found' => false], ActionNavigator::neighbours($entries, 'T9'), 'Not in this history.');
    }

    public function testEntriesWithoutTransactionAreNoNeighbours(): void
    {
        $entries = [self::entry(3, 'T3'), self::entry(2, null), self::entry(1, 'T1')];

        $this->assertSame(['newer' => null, 'older' => 'T1', 'found' => true], ActionNavigator::neighbours($entries, 'T3'), 'No link to an entry without transaction.');
    }

    public function testStopsOnceTheOlderActionIsFound(): void
    {
        $read = 0;
        $entries = (static function () use (&$read): \Generator {
            foreach ([self::entry(3, 'T3'), self::entry(2, 'T2'), self::entry(1, 'T1')] as $entry) {
                ++$read;

                yield $entry;
            }
        })();

        ActionNavigator::neighbours($entries, 'T3');

        $this->assertSame(2, $read);
    }

    public function testGivesUpBeyondTheLimit(): void
    {
        $entries = [];
        foreach (range(ActionNavigator::MAX_ENTRIES + 1, 1, -1) as $id) {
            $entries[] = self::entry($id, 'T'.$id);
        }

        $this->assertSame(['newer' => null, 'older' => null, 'found' => false], ActionNavigator::neighbours($entries, 'T1'), 'Too far in the history.');
    }

    private static function entry(int $id, ?string $transactionId): Entry
    {
        return Entry::fromArray(['id' => $id, 'schema_version' => 2, 'type' => 'update', 'object_id' => '1', 'transaction_id' => $transactionId, 'diffs' => '{}', 'created_at' => new \DateTimeImmutable('@'.(1_700_000_000 + $id))]);
    }
}
