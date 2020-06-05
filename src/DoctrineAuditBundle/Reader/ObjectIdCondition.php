<?php


namespace DH\DoctrineAuditBundle\Reader;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

class ObjectIdCondition implements ConditionInterface
{
    /**
     * @var null|int|string
     */
    private $id;

    /**
     * Apply object id filtering
     *
     * @param null|int|string $id
     */
    public function __construct($id = null)
    {
        $this->id = $id;
    }

    public function apply(QueryBuilder $queryBuilder)
    {
        if (null !== $this->id) {
            $queryBuilder
                ->andWhere('object_id = :object_id')
                ->setParameter('object_id', $this->id)
            ;
        }
    }
}
