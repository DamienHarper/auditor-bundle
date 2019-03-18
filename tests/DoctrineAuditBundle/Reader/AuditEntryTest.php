<?php

namespace DH\DoctrineAuditBundle\Tests\Reader;

use DH\DoctrineAuditBundle\Reader\AuditEntry;
use DH\DoctrineAuditBundle\User\User;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DH\DoctrineAuditBundle\Reader\AuditEntry
 */
class AuditEntryTest extends TestCase
{
    public function testAccessors(): void
    {
        $entry = new AuditEntry();
        $entry->id = 1;
        $entry->type = 'type';
        $entry->object_id = '1';
        $entry->diffs = '{}';
        $entry->blame_id = 1;
        $entry->blame_user = 'John Doe';
        $entry->blame_user_fqdn = User::class;
        $entry->blame_user_firewall = 'main';
        $entry->ip = '1.2.3.4';
        $entry->created_at = 'now';

        $this->assertSame(1, $entry->getId(), 'AuditEntry::getId() is ok.');
        $this->assertSame('type', $entry->getType(), 'AuditEntry::getType() is ok.');
        $this->assertSame('1', $entry->getObjectId(), 'AuditEntry::getObjectId() is ok.');
        $this->assertSame([], $entry->getDiffs(), 'AuditEntry::getDiffs() is ok.');
        $this->assertSame(1, $entry->getUserId(), 'AuditEntry::getUserId() is ok.');
        $this->assertSame('John Doe', $entry->getUsername(), 'AuditEntry::getUsername() is ok.');
        $this->assertSame(User::class, $entry->getUserFqdn(), 'AuditEntry::getUserFqdn() is ok.');
        $this->assertSame('main', $entry->getUserFirewall(), 'AuditEntry::getUserFirewall() is ok.');
        $this->assertSame('1.2.3.4', $entry->getIp(), 'AuditEntry::getIp() is ok.');
        $this->assertSame('now', $entry->getCreatedAt(), 'AuditEntry::getCreatedAt() is ok.');
    }

    public function testUndefinedUser(): void
    {
        $entry = new AuditEntry();

        $this->assertSame('Unknown', $entry->getUserId(), 'AuditEntry::getUserId() is ok with undefined user.');
        $this->assertSame('Unknown', $entry->getUsername(), 'AuditEntry::getUsername() is ok with undefined user.');
    }

    public function testGetDiffsReturnsAnArray(): void
    {
        $entry = new AuditEntry();
        $entry->diffs = '{}';

        $this->assertIsArray($entry->getDiffs(), 'AuditEntry::getDiffs() returns an array.');
    }
}
