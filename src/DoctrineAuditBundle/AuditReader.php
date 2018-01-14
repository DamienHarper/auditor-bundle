<?php
namespace DH\DoctrineAuditBundle;

use Doctrine\ORM\EntityManagerInterface;

class AuditReader
{
    /**
     * @var AuditConfiguration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(AuditConfiguration $configuration, EntityManagerInterface $entityManager)
    {
        $this->configuration = $configuration;
        $this->entityManager = $entityManager;
    }

    public function getAuditedEntities()
    {
        $entities = $this->entityManager->getConfiguration()->getMetadataDriverImpl()->getAllClassNames();
        $audited = [];
        foreach ($entities as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $audited[$entity] = $this->getEntityTableName($entity);
            }
        }
        ksort($audited);

        return $audited;
    }

    public function getAudits(string $entity, int $id = null, int $page = 1, int $pageSize = 50)
    {
        $connection = $this->entityManager->getConnection();
        $auditTable = implode('', [
            $this->configuration->getTablePrefix(),
            $this->getEntityTableName($entity),
            $this->configuration->getTableSuffix()
        ]);

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($auditTable)
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
        ;

        if ($id !== null) {
            $queryBuilder
                ->where('object_id = :object_id')
                ->setParameter('object_id', $id)
            ;
        }

        return $queryBuilder
            ->execute()
            ->fetchAll(\PDO::FETCH_CLASS, AuditEntry::class)
        ;
    }

    public function getAudit(string $entity, int $id)
    {
        $connection = $this->entityManager->getConnection();
        $auditTable = implode('', [
            $this->configuration->getTablePrefix(),
            $this->getEntityTableName($entity),
            $this->configuration->getTableSuffix()
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

        return $queryBuilder
            ->execute()
            ->fetchAll(\PDO::FETCH_CLASS, AuditEntry::class)
        ;
    }

    /**
     * Returns the table name of $entity
     *
     * @param EntityManagerInterface $em
     * @param $entity
     * @return string
     */
    public function getEntityTableName($entity): string
    {
        return $this
            ->entityManager
            ->getClassMetadata($entity)
            ->table['name']
        ;
    }
}
