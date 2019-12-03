<?php

namespace DH\DoctrineAuditBundle\Reader;

use ArrayIterator;
use Countable;
use Doctrine\DBAL\Query\QueryBuilder;
use IteratorAggregate;

class Paginator implements Countable, IteratorAggregate
{
    /**
     * @var QueryBuilder
     */
    private $queryBuilder;

    /**
     * @var int
     */
    private $count;

    public function __construct($queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    public function count()
    {
        $queryBuilder = $this->cloneQuery($this->queryBuilder);

        $result = $queryBuilder
            ->resetQueryPart('select')
            ->resetQueryPart('orderBy')
            ->setMaxResults(null)
            ->setFirstResult(null)
            ->select('COUNT(id)')
            ->execute()
            ->fetchColumn(0)
        ;

        $this->count = false === $result ? 0 : $result;

        return $this->count;
    }

    public function getIterator()
    {
        $offset = $this->queryBuilder->getFirstResult();
        $length = $this->queryBuilder->getMaxResults();

        $result = $this->cloneQuery($this->queryBuilder)
            ->setMaxResults($length)
            ->setFirstResult($offset)
            ->execute()
            ->fetchAll()
        ;

        return new ArrayIterator($result);
    }

    private function cloneQuery(QueryBuilder $queryBuilder): QueryBuilder
    {
        /** @var QueryBuilder $cloneQuery */
        $cloneQuery = clone $queryBuilder;
        $cloneQuery->setParameters($queryBuilder->getParameters());

        return $cloneQuery;
    }
}
