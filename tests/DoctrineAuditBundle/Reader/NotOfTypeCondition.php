<?php


namespace DH\DoctrineAuditBundle\Tests\Reader;


use DH\DoctrineAuditBundle\Reader\ConditionInterface;
use Doctrine\DBAL\Query\QueryBuilder;

class NotOfTypeCondition implements ConditionInterface
{
    private $type;

    public function __construct($type)
    {
        $this->type = $type;
    }

    public function apply(QueryBuilder $queryBuilder)
    {
        if (!empty($this->type)) {
            $queryBuilder
                ->andWhere('type <> :type')
                ->setParameter('type', $this->type)
            ;
        }
    }
}
