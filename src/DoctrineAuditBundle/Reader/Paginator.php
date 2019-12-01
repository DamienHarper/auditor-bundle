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
    private $query;

    /**
     * @var int
     */
    private $count;

    public function __construct($query)
    {
        $this->query = $query;
    }

    public function count()
    {
        $queryBuilder = $this->cloneQuery($this->query);

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
        $offset = $this->query->getFirstResult();
        $length = $this->query->getMaxResults();

        $result = $this->cloneQuery($this->query)
            ->setMaxResults($length)
            ->setFirstResult($offset)
            ->execute()
            ->fetchAll()
        ;

        return new ArrayIterator($result);
    }

    private function cloneQuery(QueryBuilder $query): QueryBuilder
    {
        /** @var QueryBuilder $cloneQuery */
        $cloneQuery = clone $query;
        $cloneQuery->setParameters($query->getParameters());

        return $cloneQuery;
    }
}
