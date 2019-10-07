<?php

namespace DH\DoctrineAuditBundle\DBAL;

use Doctrine\DBAL\Logging\SQLLogger;

class AuditLoggerChain implements SQLLogger
{
    /** @var SQLLogger[] */
    private $loggers = [];

    /**
     * Adds a logger in the chain.
     *
     * @param SQLLogger $logger
     */
    public function addLogger(SQLLogger $logger)
    {
        $this->loggers[] = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null)
    {
        foreach ($this->loggers as $logger) {
            $logger->startQuery($sql, $params, $types);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopQuery()
    {
        foreach ($this->loggers as $logger) {
            $logger->stopQuery();
        }
    }

    /**
     * @return SQLLogger[]
     */
    public function getLoggers()
    {
        return $this->loggers;
    }
}
