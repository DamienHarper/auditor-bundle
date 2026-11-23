<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\History;

use DH\Auditor\Model\Entry;
use DH\AuditorBundle\History\SortKey;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class SortKeyTest extends TestCase
{
    public function testComparesWithTheSortKeyOfAnEntry(): void
    {
        $entry = Entry::fromArray([
            'id' => 12,
            'type' => 'update',
            'object_id' => '1',
            'transaction_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
            'created_at' => new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC')),
        ]);

        $this->assertLessThan($entry->getSortKey(), SortKey::of(new \DateTimeImmutable('2026-11-20 09:59:59.999999', new \DateTimeZone('UTC'))));
        $this->assertGreaterThan($entry->getSortKey(), SortKey::of(new \DateTimeImmutable('2026-11-20 10:00:00.000001', new \DateTimeZone('UTC'))));
        $this->assertSame(
            SortKey::of(new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC')), 'comment-3'),
            SortKey::of(new \DateTimeImmutable('2026-11-20 11:00:00', new \DateTimeZone('Europe/Paris')), 'comment-3'),
            'Dates are compared in UTC.',
        );
        $this->assertLessThan(SortKey::of(new \DateTimeImmutable('@0'), 'b'), SortKey::of(new \DateTimeImmutable('@0'), 'a'));
        $this->assertLessThan($entry->getSortKey(), SortKey::of(new \DateTimeImmutable('2026-11-20 10:00:00', new \DateTimeZone('UTC'))), 'At the same instant, without discriminator, an element sorts before (below) the entry.');
    }
}
