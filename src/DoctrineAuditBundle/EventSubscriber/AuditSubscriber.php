<?php

namespace DH\DoctrineAuditBundle\EventSubscriber;

use DH\DoctrineAuditBundle\AuditManager;
use DH\DoctrineAuditBundle\DBAL\AuditLogger;
use DH\DoctrineAuditBundle\DBAL\AuditLoggerChain;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Logging\SQLLogger;
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
        $auditLogger = new AuditLogger(function () use ($em) {
            // flushes pending data
            $em->getConnection()->getConfiguration()->setSQLLogger($this->loggerBackup);
            $uow = $em->getUnitOfWork();

            $this->manager->processInsertions($em, $uow);
            $this->manager->processUpdates($em, $uow);
            $this->manager->processAssociations($em);
            $this->manager->processDissociations($em);
            $this->manager->processDeletions($em);

            $this->manager->reset();
        });

        // Initialize a new LoggerChain with the new AuditLogger + the existing SQLLoggers.
        $loggerChain = new AuditLoggerChain();
        $loggerChain->addLogger($auditLogger);
        if ($this->loggerBackup instanceof AuditLoggerChain) {
            /** @var SQLLogger $logger */
            foreach ($this->loggerBackup->getLoggers() as $logger) {
                $loggerChain->addLogger($logger);
            }
        } elseif ($this->loggerBackup instanceof SQLLogger) {
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
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }
}
