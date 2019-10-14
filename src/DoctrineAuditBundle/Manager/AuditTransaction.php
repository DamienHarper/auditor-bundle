<?php

namespace DH\DoctrineAuditBundle\Manager;

use DH\DoctrineAuditBundle\Helper\AuditHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;

class AuditTransaction
{
    /**
     * @var \DH\DoctrineAuditBundle\AuditConfiguration
     */
    private $configuration;

    /**
     * @var AuditHelper
     */
    private $helper;

    /**
     * @var null|string
     */
    private $transaction_hash;

    /**
     * @var array
     */
    private $inserted = [];     // [$source, $changeset]

    /**
     * @var array
     */
    private $updated = [];      // [$source, $changeset]

    /**
     * @var array
     */
    private $removed = [];      // [$source, $id]

    /**
     * @var array
     */
    private $associated = [];   // [$source, $target, $mapping]

    /**
     * @var array
     */
    private $dissociated = [];  // [$source, $target, $id, $mapping]

    /**
     * @var EntityManagerInterface
     */
    private $em;

    public function __construct(AuditHelper $helper)
    {
        $this->helper = $helper;
        $this->configuration = $helper->getConfiguration();
        $this->em = $this->configuration->getEntityManager();
    }

    /**
     * Returns transaction hash.
     *
     * @return string
     */
    public function getTransactionHash(): string
    {
        if (null === $this->transaction_hash) {
            $this->transaction_hash = sha1(uniqid('tid', true));
        }

        return $this->transaction_hash;
    }

    public function collect(): void
    {
        $uow = $this->em->getUnitOfWork();

        $this->collectScheduledInsertions($uow);
        $this->collectScheduledUpdates($uow);
        $this->collectScheduledDeletions($uow, $this->em);
        $this->collectScheduledCollectionUpdates($uow, $this->em);
        $this->collectScheduledCollectionDeletions($uow, $this->em);
    }

    /**
     * @param UnitOfWork $uow
     */
    public function collectScheduledInsertions(UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $this->inserted[] = [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ];
            }
        }
    }

    /**
     * @param UnitOfWork $uow
     */
    public function collectScheduledUpdates(UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $this->updated[] = [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ];
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
    public function collectScheduledDeletions(UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $uow->initializeObject($entity);
                $this->removed[] = [
                    $entity,
                    $this->helper->id($em, $entity),
                ];
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
    public function collectScheduledCollectionUpdates(UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if ($this->configuration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->getInsertDiff() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $this->associated[] = [
                            $collection->getOwner(),
                            $entity,
                            $mapping,
                        ];
                    }
                }
                foreach ($collection->getDeleteDiff() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $this->dissociated[] = [
                            $collection->getOwner(),
                            $entity,
                            $this->helper->id($em, $entity),
                            $mapping,
                        ];
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
    public function collectScheduledCollectionDeletions(UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            if ($this->configuration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->toArray() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $this->dissociated[] = [
                            $collection->getOwner(),
                            $entity,
                            $this->helper->id($em, $entity),
                            $mapping,
                        ];
                    }
                }
            }
        }
    }

    public function getInserted(): array
    {
        return $this->inserted;
    }

    public function getUpdated(): array
    {
        return $this->updated;
    }

    public function getRemoved(): array
    {
        return $this->removed;
    }

    public function getAssociated(): array
    {
        return $this->associated;
    }

    public function getDissociated(): array
    {
        return $this->dissociated;
    }

    public function getEntityManager(): EntityManagerInterface
    {
        return $this->em;
    }
}
