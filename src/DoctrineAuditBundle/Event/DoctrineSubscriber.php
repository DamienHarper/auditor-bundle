<?php

namespace DH\DoctrineAuditBundle\Event;

use DH\DoctrineAuditBundle\DBAL\Logger;
use DH\DoctrineAuditBundle\DBAL\LoggerChain;
use DH\DoctrineAuditBundle\Manager\TransactionManager;
use DH\DoctrineAuditBundle\Model\Transaction;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

class DoctrineSubscriber implements EventSubscriber
{
    /**
     * @var TransactionManager
     */
    private $manager;

    /**
     * @var ?SQLLogger
     */
    private $loggerBackup;

    public function __construct(TransactionManager $manager)
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
        $transaction = new Transaction($this->manager->getHelper());

        // extend the SQL logger
        $this->loggerBackup = $em->getConnection()->getConfiguration()->getSQLLogger();
        $auditLogger = new Logger(function () use ($em, $transaction): void {
            // flushes pending data
            $em->getConnection()->getConfiguration()->setSQLLogger($this->loggerBackup);
            $this->manager->process($transaction);
        });

        // Initialize a new LoggerChain with the new AuditLogger + the existing SQLLoggers.
        $loggerChain = new LoggerChain();
        $loggerChain->addLogger($auditLogger);
        if ($this->loggerBackup instanceof LoggerChain) {
            /** @var SQLLogger $logger */
            foreach ($this->loggerBackup->getLoggers() as $logger) {
                $loggerChain->addLogger($logger);
            }
        } elseif ($this->loggerBackup instanceof SQLLogger) {
            $loggerChain->addLogger($this->loggerBackup);
        }
        $em->getConnection()->getConfiguration()->setSQLLogger($loggerChain);

        // Populate transaction
        $this->manager->populate($transaction);
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }
}
