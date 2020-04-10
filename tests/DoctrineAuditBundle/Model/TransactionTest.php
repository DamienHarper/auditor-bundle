<?php

namespace DH\DoctrineAuditBundle\Tests\Model;

use DH\DoctrineAuditBundle\Model\Transaction;
use DH\DoctrineAuditBundle\Tests\BaseTest;

/**
 * @internal
 */
final class TransactionTest extends BaseTest
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public function testGetTransactionHash(): void
    {
        $transaction = new Transaction();

        $transaction_hash = $transaction->getTransactionHash();
        self::assertNotNull($transaction_hash, 'transaction_hash is not null');
        self::assertIsString($transaction_hash, 'transaction_hash is a string');
        self::assertSame(40, mb_strlen($transaction_hash), 'transaction_hash is a string of 40 characters');
    }
}
