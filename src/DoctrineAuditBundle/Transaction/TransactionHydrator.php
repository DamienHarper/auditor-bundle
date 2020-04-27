<?php

namespace DH\DoctrineAuditBundle\Transaction;

use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Model\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;

class TransactionHydrator
{
    use AuditTrait;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
        $this->em = $this->configuration->getEntityManager();
    }

    public function hydrate(Transaction $transaction): void
    {
        $uow = $this->em->getUnitOfWork();

        $this->hydrateWithScheduledInsertions($transaction, $uow);
        $this->hydrateWithScheduledUpdates($transaction, $uow);
        $this->hydrateWithScheduledDeletions($transaction, $uow, $this->em);
        $this->hydrateWithScheduledCollectionUpdates($transaction, $uow, $this->em);
        $this->hydrateWithScheduledCollectionDeletions($transaction, $uow, $this->em);
    }

    /**
     * @param UnitOfWork $uow
     */
    private function hydrateWithScheduledInsertions(Transaction $transaction, UnitOfWork $uow): void
    {
        foreach (array_reverse($uow->getScheduledEntityInsertions()) as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $transaction->trackAuditEvent(Transaction::INSERT, [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ]);
            }
        }
    }

    /**
     * @param UnitOfWork $uow
     */
    private function hydrateWithScheduledUpdates(Transaction $transaction, UnitOfWork $uow): void
    {
        foreach (array_reverse($uow->getScheduledEntityUpdates()) as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $transaction->trackAuditEvent(Transaction::UPDATE, [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ]);
            }
        }
    }

    /**
     * @param UnitOfWork             $uow
     * @param EntityManagerInterface $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function hydrateWithScheduledDeletions(Transaction $transaction, UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach (array_reverse($uow->getScheduledEntityDeletions()) as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $uow->initializeObject($entity);
                $transaction->trackAuditEvent(Transaction::REMOVE, [
                    $entity,
                    $this->id($em, $entity),
                ]);
            }
        }
    }

    /**
     * @param UnitOfWork             $uow
     * @param EntityManagerInterface $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function hydrateWithScheduledCollectionUpdates(Transaction $transaction, UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach (array_reverse($uow->getScheduledCollectionUpdates()) as $collection) {
            if ($this->configuration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->getInsertDiff() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $transaction->trackAuditEvent(Transaction::ASSOCIATE, [
                            $collection->getOwner(),
                            $entity,
                            $mapping,
                        ]);
                    }
                }
                foreach ($collection->getDeleteDiff() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $transaction->trackAuditEvent(Transaction::DISSOCIATE, [
                            $collection->getOwner(),
                            $entity,
                            $this->id($em, $entity),
                            $mapping,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * @param UnitOfWork             $uow
     * @param EntityManagerInterface $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function hydrateWithScheduledCollectionDeletions(Transaction $transaction, UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach (array_reverse($uow->getScheduledCollectionDeletions()) as $collection) {
            if ($this->configuration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->toArray() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $transaction->trackAuditEvent(Transaction::DISSOCIATE, [
                            $collection->getOwner(),
                            $entity,
                            $this->id($em, $entity),
                            $mapping,
                        ]);
                    }
                }
            }
        }
    }
}
