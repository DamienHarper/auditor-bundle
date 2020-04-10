<?php

namespace DH\DoctrineAuditBundle\Manager;

use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Model\Transaction;
use DH\DoctrineAuditBundle\Transaction\TransactionHydrator;
use DH\DoctrineAuditBundle\Transaction\TransactionProcessor;
use Doctrine\ORM\EntityManagerInterface;

class TransactionManager
{
    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var TransactionProcessor
     */
    private $processor;

    /**
     * @var TransactionHydrator
     */
    private $hydrator;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;

        $this->processor = new TransactionProcessor($configuration);
        $this->hydrator = new TransactionHydrator($configuration);
    }

    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public function populate(Transaction $transaction): void
    {
        $this->hydrator->hydrate($transaction);
    }

    public function process(Transaction $transaction): void
    {
        $this->processor->process($transaction);
    }

    /**
     * @param EntityManagerInterface $em
     *
     * @return EntityManagerInterface
     */
    public function selectStorageSpace(EntityManagerInterface $em): EntityManagerInterface
    {
        return $this->configuration->getEntityManager() ?? $em;
    }
}
