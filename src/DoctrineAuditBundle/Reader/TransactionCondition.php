<?php


namespace DH\DoctrineAuditBundle\Reader;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

class TransactionCondition implements ConditionInterface
{
    /**
     * @var string
     */
    private $transactionHash;

    /**
     * Apply transaction filtering
     *
     * @param array $types
     */
    public function __construct($transactionHash)
    {
        $this->transactionHash = $transactionHash;
    }

    public function apply(QueryBuilder $queryBuilder)
    {
        if (null !== $this->transactionHash) {
            $queryBuilder
                ->andWhere('transaction_hash = :transaction_hash')
                ->setParameter('transaction_hash', $this->transactionHash)
            ;
        }
    }
}
