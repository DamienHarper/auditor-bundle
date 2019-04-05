<?php

namespace DH\DoctrineAuditBundle\Reader;

use DH\DoctrineAuditBundle\AuditConfiguration;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;

class AuditReader
{
    const UPDATE = 'update';
    const ASSOCIATE = 'associate';
    const DISSOCIATE = 'dissociate';
    const INSERT = 'insert';
    const REMOVE = 'remove';

    /**
     * @var AuditConfiguration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var ?string
     */
    private $filter;

    /**
     * AuditReader constructor.
     *
     * @param AuditConfiguration     $configuration
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(AuditConfiguration $configuration, EntityManagerInterface $entityManager)
    {
        $this->configuration = $configuration;
        $this->entityManager = $entityManager;
    }

    /**
     * @return AuditConfiguration
     */
    public function getConfiguration(): AuditConfiguration
    {
        return $this->configuration;
    }

    /**
     * Set the filter for AuditEntry retrieving.
     *
     * @param string $filter
     *
     * @return AuditReader
     */
    public function filterBy(string $filter): self
    {
        if (!\in_array($filter, [self::UPDATE, self::ASSOCIATE, self::DISSOCIATE, self::INSERT, self::REMOVE], true)) {
            $this->filter = null;
        } else {
            $this->filter = $filter;
        }

        return $this;
    }

    /**
     * Returns current filter.
     *
     * @return null|string
     */
    public function getFilter(): ?string
    {
        return $this->filter;
    }

    /**
     * Returns an array of audit table names indexed by entity FQN.
     *
     * @throws \Doctrine\ORM\ORMException
     *
     * @return array
     */
    public function getEntities(): array
    {
        $metadataDriver = $this->entityManager->getConfiguration()->getMetadataDriverImpl();
        $entities = [];
        if (null !== $metadataDriver) {
            $entities = $metadataDriver->getAllClassNames();
        }
        $audited = [];
        foreach ($entities as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $audited[$entity] = $this->getEntityTableName($entity);
            }
        }
        ksort($audited);

        return $audited;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string    $id
     * @param int           $page
     * @param int           $pageSize
     *
     * @return array
     */
    public function getAudits($entity, $id = null, int $page = 1, int $pageSize = 50): array
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('$page must be greater or equal than 1.');
        }
        if ($pageSize < 1) {
            throw new \InvalidArgumentException('$pageSize must be greater or equal than 1.');
        }

        $connection = $this->entityManager->getConnection();
        $schema = $this->entityManager->getClassMetadata($entity)->getSchemaName();

        $auditTable = implode('', [
            null === $schema ? '' : $schema.'.',
            $this->configuration->getTablePrefix(),
            $this->getEntityTableName(\is_string($entity) ? $entity : \get_class($entity)),
            $this->configuration->getTableSuffix(),
        ]);

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($auditTable)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        if (null !== $id) {
            $queryBuilder
                ->where('object_id = :object_id')
                ->setParameter('object_id', $id);
        }

        if (null !== $this->filter) {
            $queryBuilder
                ->andWhere('type = :filter')
                ->setParameter('filter', $this->filter);
        }

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(\PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    /**
     * @param object|string $entity
     * @param int|string    $id
     *
     * @return mixed
     */
    public function getAudit($entity, $id)
    {
        $connection = $this->entityManager->getConnection();
        $schema = $this->entityManager->getClassMetadata($entity)->getSchemaName();

        $auditTable = implode('', [
            null === $schema ? '' : $schema.'.',
            $this->configuration->getTablePrefix(),
            $this->getEntityTableName(\is_string($entity) ? $entity : \get_class($entity)),
            $this->configuration->getTableSuffix(),
        ]);

        /**
         * @var \Doctrine\DBAL\Query\QueryBuilder
         */
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($auditTable)
            ->where('id = :id')
            ->setParameter('id', $id);

        if (null !== $this->filter) {
            $queryBuilder
                ->andWhere('type = :filter')
                ->setParameter('filter', $this->filter);
        }

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(\PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    /**
     * Returns the table name of $entity.
     *
     * @param object|string $entity
     *
     * @return string
     */
    public function getEntityTableName($entity): string
    {
        return $this
            ->entityManager
            ->getClassMetadata($entity)
            ->getTableName();
    }
}
