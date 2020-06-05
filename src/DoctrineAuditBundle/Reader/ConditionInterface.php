<?php


namespace DH\DoctrineAuditBundle\Reader;


use Doctrine\DBAL\Query\QueryBuilder;

interface ConditionInterface
{
    public function apply(QueryBuilder $queryBuilder);
}
