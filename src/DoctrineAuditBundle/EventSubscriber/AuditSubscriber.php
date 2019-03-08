<?php

namespace DH\DoctrineAuditBundle\EventSubscriber;

use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\DBAL\AuditLogger;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Logging\LoggerChain;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

class AuditSubscriber implements EventSubscriber
{
    /**
     * @var AuditManager
     */
    private $manager;

    /**
     * @var ?SQLLogger
     */
    private $loggerBackup;

    private $inserted = [];     // [$source, $changeset]
    private $updated = [];      // [$source, $changeset]
    private $removed = [];      // [$source, $id]
    private $associated = [];   // [$source, $target, $mapping]
    private $dissociated = [];  // [$source, $target, $id, $mapping]

    public function __construct(AuditManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Handles soft-delete events from Gedmo\SoftDeleteable filter.
     *
     * @see https://symfony.com/doc/current/bundles/StofDoctrineExtensionsBundle/index.html
     *
     * @param LifecycleEventArgs $args
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function preSoftDelete(LifecycleEventArgs $args): void
    {
        $entity = $args->getEntity();
        $em = $args->getEntityManager();

        if ($this->manager->getAuditConfiguration()->isAudited($entity)) {
            $this->removed[] = [
                $entity,
                $this->manager->getHelper()->id($em, $entity),
            ];
        }
    }

    /**
     * It is called inside EntityManager#flush() after the changes to all the managed entities
     * and their associations have been computed.
     *
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/events.html#onflush
     *
     * @param OnFlushEventArgs $args
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        // extend the SQL logger
        $this->loggerBackup = $em->getConnection()->getConfiguration()->getSQLLogger();
        $loggerChain = new LoggerChain();
        $loggerChain->addLogger(new AuditLogger(function () use ($em) {
            $this->flush($em);
        }));
        if ($this->loggerBackup instanceof SQLLogger) {
            $loggerChain->addLogger($this->loggerBackup);
        }
        $em->getConnection()->getConfiguration()->setSQLLogger($loggerChain);

        $this->collectScheduledInsertions($uow);
        $this->collectScheduledUpdates($uow);
        $this->collectScheduledDeletions($uow, $em);
        $this->collectScheduledCollectionUpdates($uow, $em);
        $this->collectScheduledCollectionDeletions($uow, $em);
    }

    /**
     * Flushes pending data.
     *
     * @param EntityManager $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function flush(EntityManager $em): void
    {
        $em->getConnection()->getConfiguration()->setSQLLogger($this->loggerBackup);
        $uow = $em->getUnitOfWork();

        $this->processInsertions($em, $uow);
        $this->processUpdates($em, $uow);
        $this->processAssociations($em);
        $this->processDissociations($em);
        $this->processDeletions($em);

        $this->inserted = [];
        $this->updated = [];
        $this->removed = [];
        $this->associated = [];
        $this->dissociated = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, 'preSoftDelete'];
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     */
    private function collectScheduledInsertions(\Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->manager->getAuditConfiguration()->isAudited($entity)) {
                $this->inserted[] = [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ];
            }
        }
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     */
    private function collectScheduledUpdates(\Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->manager->getAuditConfiguration()->isAudited($entity)) {
                $this->updated[] = [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ];
            }
        }
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     * @param EntityManager            $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function collectScheduledDeletions(\Doctrine\ORM\UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->manager->getAuditConfiguration()->isAudited($entity)) {
                $uow->initializeObject($entity);
                $this->removed[] = [
                    $entity,
                    $this->manager->getHelper()->id($em, $entity),
                ];
            }
        }
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     * @param EntityManager            $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function collectScheduledCollectionUpdates(\Doctrine\ORM\UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if ($this->manager->getAuditConfiguration()->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->getInsertDiff() as $entity) {
                    if ($this->manager->getAuditConfiguration()->isAudited($entity)) {
                        $this->associated[] = [
                            $collection->getOwner(),
                            $entity,
                            $mapping,
                        ];
                    }
                }
                foreach ($collection->getDeleteDiff() as $entity) {
                    if ($this->manager->getAuditConfiguration()->isAudited($entity)) {
                        $this->dissociated[] = [
                            $collection->getOwner(),
                            $entity,
                            $this->manager->getHelper()->id($em, $entity),
                            $mapping,
                        ];
                    }
                }
            }
        }
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     * @param EntityManager            $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function collectScheduledCollectionDeletions(\Doctrine\ORM\UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            if ($this->manager->getAuditConfiguration()->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->toArray() as $entity) {
                    if (!$this->manager->getAuditConfiguration()->isAudited($entity)) {
                        continue;
                    }
                    $this->dissociated[] = [
                        $collection->getOwner(),
                        $entity,
                        $this->manager->getHelper()->id($em, $entity),
                        $mapping,
                    ];
                }
            }
        }
    }

    /**
     * @param EntityManager            $em
     * @param \Doctrine\ORM\UnitOfWork $uow
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processInsertions(EntityManager $em, \Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($this->inserted as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->manager->insert($em, $entity, $ch);
        }
    }

    /**
     * @param EntityManager            $em
     * @param \Doctrine\ORM\UnitOfWork $uow
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processUpdates(EntityManager $em, \Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($this->updated as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->manager->update($em, $entity, $ch);
        }
    }

    /**
     * @param EntityManager $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processAssociations(EntityManager $em): void
    {
        foreach ($this->associated as list($source, $target, $mapping)) {
            $this->manager->associate($em, $source, $target, $mapping);
        }
    }

    /**
     * @param EntityManager $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processDissociations(EntityManager $em): void
    {
        foreach ($this->dissociated as list($source, $target, $id, $mapping)) {
            $this->manager->dissociate($em, $source, $target, $mapping);
        }
    }

    /**
     * @param EntityManager $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processDeletions(EntityManager $em): void
    {
        foreach ($this->removed as list($entity, $id)) {
            $this->manager->remove($em, $entity, $id);
        }
    }
}
