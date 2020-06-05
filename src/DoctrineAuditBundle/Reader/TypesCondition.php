<?php


namespace DH\DoctrineAuditBundle\Reader;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

class TypesCondition implements ConditionInterface
{
    /**
     * @var array
     */
    private $types;

    /**
     * Apply types filtering
     *
     * @param array $types
     */
    public function __construct($types)
    {
        $this->types = $types;
    }

    public function apply(QueryBuilder $queryBuilder)
    {
        if (!empty($this->types)) {
            $queryBuilder
                ->andWhere('type IN (:filters)')
                ->setParameter('filters', $this->types, Connection::PARAM_STR_ARRAY)
            ;
        }
    }
}
