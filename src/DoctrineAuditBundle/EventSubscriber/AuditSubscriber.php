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

        $this->manager->softDelete($em, $entity);
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

        $this->manager->collectScheduledInsertions($uow);
        $this->manager->collectScheduledUpdates($uow);
        $this->manager->collectScheduledDeletions($uow, $em);
        $this->manager->collectScheduledCollectionUpdates($uow, $em);
        $this->manager->collectScheduledCollectionDeletions($uow, $em);
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

        $this->manager->processInsertions($em, $uow);
        $this->manager->processUpdates($em, $uow);
        $this->manager->processAssociations($em);
        $this->manager->processDissociations($em);
        $this->manager->processDeletions($em);

        $this->manager->reset();
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, 'preSoftDelete'];
    }
}
