<?php

namespace DH\DoctrineAuditBundle\DBAL;

use Doctrine\DBAL\Logging\SQLLogger;

class LoggerChain implements SQLLogger
{
    /**
     * @var SQLLogger[]
     */
    private $loggers = [];

    /**
     * Adds a logger in the chain.
     *
     * @param SQLLogger $logger
     */
    public function addLogger(SQLLogger $logger): void
    {
        $this->loggers[] = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null): void
    {
        foreach ($this->loggers as $logger) {
            $logger->startQuery($sql, $params, $types);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stopQuery(): void
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
