<?php

namespace DH\DoctrineAuditBundle\Tests\Reader;

use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Model\Entry;
use DH\DoctrineAuditBundle\Reader\Reader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Author;

/**
 * @internal
 */
final class CustomConditionTest extends CoreTest
{
    public function testAddCustomCondition(): void
    {
        $reader = $this->getReader($this->getAuditConfiguration());
        $reader->addCondition(new NotOfTypeCondition(Reader::REMOVE));

        /** @var Entry[] $audits */
        $audits = $reader->getAudits(Author::class, null, 1, 50);

        $i = 0;
        self::assertCount(4, $audits, 'result count is ok.');
        self::assertSame(Reader::UPDATE, $audits[$i++]->getType(), 'entry'.$i.' is an update operation.');
        self::assertSame(Reader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        self::assertSame(Reader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
        self::assertSame(Reader::INSERT, $audits[$i++]->getType(), 'entry'.$i.' is an insert operation.');
    }
}
