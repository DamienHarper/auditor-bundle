<?php


namespace DH\DoctrineAuditBundle\Reader;


use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

class DateRangeCondition implements ConditionInterface
{
    /**
     * @var \DateTime|null
     */
    private $startDate;

    /**
     * @var \DateTime|null
     */
    private $endDate;

    /**
     * Apply date filtering.
     *
     * @param \DateTime|null $startDate
     * @param \DateTime|null $endDate
     */
    public function __construct(?\DateTime $startDate, ?\DateTime $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
    }

    public function apply(QueryBuilder $queryBuilder)
    {
        if (null !== $this->startDate && null !== $this->endDate && $this->endDate < $this->startDate) {
            throw new \InvalidArgumentException('End date must be greater than start date.');
        }

        if (null !== $this->startDate) {
            $queryBuilder
                ->andWhere('created_at >= :start_date')
                ->setParameter('start_date', $this->startDate->format('Y-m-d H:i:s'))
            ;
        }

        if (null !== $this->endDate) {
            $queryBuilder
                ->andWhere('created_at <= :end_date')
                ->setParameter('end_date', $this->endDate->format('Y-m-d H:i:s'))
            ;
        }
    }
}
