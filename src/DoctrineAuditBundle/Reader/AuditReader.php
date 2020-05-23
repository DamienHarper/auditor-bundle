<?php

namespace DH\DoctrineAuditBundle\Reader;

use DateTime;
use DH\DoctrineAuditBundle\Annotation\Security;
use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Exception\AccessDeniedException;
use DH\DoctrineAuditBundle\Exception\InvalidArgumentException;
use DH\DoctrineAuditBundle\User\UserInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata as ORMMetadata;
use PDO;
use Symfony\Component\Security\Core\Security as CoreSecurity;

class AuditReader
{
    public const UPDATE = 'update';
    public const ASSOCIATE = 'associate';
    public const DISSOCIATE = 'dissociate';
    public const INSERT = 'insert';
    public const REMOVE = 'remove';

    public const PAGE_SIZE = 50;

    /**
     * @var AuditConfiguration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var array
     */
    private $filters = [];

    /**
     * AuditReader constructor.
     *
     * @param AuditConfiguration     $configuration
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(
        AuditConfiguration $configuration,
        EntityManagerInterface $entityManager
    ) {
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
     * Set the filter(s) for AuditEntry retrieving.
     *
     * @param array|string $filter
     *
     * @return AuditReader
     */
    public function filterBy($filter): self
    {
        $filters = \is_array($filter) ? $filter : [$filter];

        $this->filters = array_filter($filters, static function ($f) {
            return \in_array($f, [self::UPDATE, self::ASSOCIATE, self::DISSOCIATE, self::INSERT, self::REMOVE], true);
        });

        return $this;
    }

    /**
     * Returns current filter.
     *
     * @return array
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Returns an array of audit table names indexed by entity FQN.
     *
     * @param null|string $blameId
     *
     * @throws \Doctrine\ORM\ORMException
     *
     * @return array
     */
    public function getEntities(?string $blameId = null): array
    {
        $metadataDriver = $this->entityManager->getConfiguration()->getMetadataDriverImpl();
        $entities = [];
        if (null !== $metadataDriver) {
            $entities = $metadataDriver->getAllClassNames();
        }
        $audited = [];

        foreach ($entities as $entity) {
            if ($this->configuration->isAuditable($entity)
                && (!$blameId || !empty($this->getBlame($entity, $blameId)))) {
                $audited[$entity] = $this->getEntityTableName($entity);
            }
        }
        ksort($audited);

        return $audited;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param string          $entity
     * @param null|int|string $id
     * @param null|int        $page
     * @param null|int        $pageSize
     * @param null|string     $transactionHash
     * @param bool            $strict
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return array
     */
    public function getAudits(string $entity, $id = null, ?int $page = null, ?int $pageSize = null, ?string $transactionHash = null, bool $strict = true): array
    {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id, $page, $pageSize, $transactionHash, $strict);

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    /**
     * Returns an array of all audited entries/operations for a given transaction hash
     * indexed by entity FQCN.
     *
     * @param string $transactionHash
     *
     * @throws InvalidArgumentException
     * @throws \Doctrine\ORM\ORMException
     *
     * @return array
     */
    public function getAuditsByTransactionHash(string $transactionHash): array
    {
        $results = [];

        $entities = $this->getEntities();
        foreach ($entities as $entity => $tablename) {
            try {
                $audits = $this->getAudits($entity, null, null, null, $transactionHash);
                if (\count($audits) > 0) {
                    $results[$entity] = $audits;
                }
            } catch (AccessDeniedException $e) {
                // acces denied
            }
        }

        return $results;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param string          $entity
     * @param null|int|string $id
     * @param null|DateTime   $startDate       - Expected in configured timezone
     * @param null|DateTime   $endDate         - Expected in configured timezone
     * @param null|int        $page
     * @param null|int        $pageSize
     * @param null|string     $transactionHash
     * @param bool            $strict
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return array
     */
    public function getAuditsByDate(string $entity, $id = null, ?DateTime $startDate = null, ?DateTime $endDate = null, ?int $page = null, ?int $pageSize = null, ?string $transactionHash = null, bool $strict = true): array
    {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id, $page, $pageSize, $transactionHash, $strict, $startDate, $endDate);

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param string          $entity
     * @param null|int|string $id
     * @param int             $page
     * @param int             $pageSize
     * @param null|string     $transactionHash
     * @param bool            $strict
     * @param null|DateTime   $startDate
     * @param null|DateTime   $endDate
     * @param null|string     $userId
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return array
     */
    public function getAuditsPager(
        string $entity,
        $id = null,
        ?int $page = 1,
        ?int $pageSize = self::PAGE_SIZE,
        ?string $transactionHash = null,
        bool $strict = true,
        ?DateTime $startDate = null,
        ?DateTime $endDate = null,
        ?string $userId = null
    ): array {
        $queryBuilder = $this->getAuditsQueryBuilder(
            $entity,
            $id,
            $page,
            $pageSize,
            $transactionHash,
            $strict,
            $startDate,
            $endDate,
            $userId
        );

        $paginator = new Paginator($queryBuilder);
        $numResults = $paginator->count();

        $currentPage = $page < 1 ? 1 : $page;
        $hasPreviousPage = $currentPage > 1;
        $hasNextPage = ($currentPage * $pageSize) < $numResults;

        return [
            'results' => $paginator->getIterator(),
            'currentPage' => $currentPage,
            'hasPreviousPage' => $hasPreviousPage,
            'hasNextPage' => $hasNextPage,
            'previousPage' => $hasPreviousPage ? $currentPage - 1 : null,
            'nextPage' => $hasNextPage ? $currentPage + 1 : null,
            'numPages' => (int) ceil($numResults / $pageSize),
            'haveToPaginate' => $numResults > $pageSize,
        ];
    }

    /**
     * Returns the amount of audited entries/operations.
     *
     * @param string          $entity
     * @param null|int|string $id
     * @param null|string     $blameId
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return int
     */
    public function getAuditsCount(string $entity, $id = null, ?string $blameId = null): int
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id, null, null, null, true, null, null, $blameId);

        $result = $queryBuilder
            ->resetQueryPart('select')
            ->resetQueryPart('orderBy')
            ->select('COUNT(id)')
            ->execute()
            ->fetchColumn(0)
        ;

        return false === $result ? 0 : $result;
    }

    /**
     * @param string $entity
     * @param string $id
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return mixed[]
     */
    public function getAudit(string $entity, $id): array
    {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        $connection = $this->entityManager->getConnection();

        /**
         * @var \Doctrine\DBAL\Query\QueryBuilder
         */
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($this->getEntityAuditTableName($entity))
            ->where('id = :id')
            ->setParameter('id', $id)
        ;

        $this->filterByType($queryBuilder, $this->filters);

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    public function getBlames(): array
    {
        $blames = [];

        foreach ($this->getEntities() as $entity => $tablename) {
            $blames[] = $this->getBlame($entity);
        }

        $blames = array_unique(array_merge(...$blames));

        asort($blames);

        return $blames;
    }

    public function getBlame(string $entity, ?string $blameId = null): array
    {
        $blame = [];

        $results = $this->getAuditsQueryBuilder($entity, null, null, null, null, true, null, null, $blameId)
            ->resetQueryPart('orderBy')
            ->select('blame_id, blame_user')
            ->groupBy('blame_id')
            ->addGroupBy('blame_user')
            ->execute()
            ->fetchAll()
        ;

        foreach ($results as $result) {
            //set the key to null and the user to System/Anonymous if the blame id is null
            $blame[$result['blame_id'] ?? 'null'] = $result['blame_user'] ?? ' System/Anonymous';
        }

        asort($blame);

        return $blame;
    }

    /**
     * Returns the table name of $entity.
     *
     * @param string $entity
     *
     * @return string
     */
    public function getEntityTableName(string $entity): string
    {
        return $this->entityManager->getClassMetadata($entity)->getTableName();
    }

    /**
     * Returns the audit table name for $entity.
     *
     * @param string $entity
     *
     * @return string
     */
    public function getEntityAuditTableName(string $entity): string
    {
        $schema = '';
        if ($this->entityManager->getClassMetadata($entity)->getSchemaName()) {
            $schema = $this->entityManager->getClassMetadata($entity)->getSchemaName().'.';
        }

        return sprintf('%s%s%s%s', $schema, $this->configuration->getTablePrefix(), $this->getEntityTableName($entity), $this->configuration->getTableSuffix());
    }

    /**
     * @return EntityManagerInterface
     */
    public function getEntityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }

    private function filterByType(QueryBuilder $queryBuilder, array $filters): QueryBuilder
    {
        if (!empty($filters)) {
            $queryBuilder
                ->andWhere('type IN (:filters)')
                ->setParameter('filters', $filters, Connection::PARAM_STR_ARRAY)
            ;
        }

        return $queryBuilder;
    }

    private function filterByTransaction(QueryBuilder $queryBuilder, ?string $transactionHash): QueryBuilder
    {
        if (null !== $transactionHash) {
            $queryBuilder
                ->andWhere('transaction_hash = :transaction_hash')
                ->setParameter('transaction_hash', $transactionHash)
            ;
        }

        return $queryBuilder;
    }

    private function filterByDate(QueryBuilder $queryBuilder, ?DateTime $startDate, ?DateTime $endDate): QueryBuilder
    {
        if (null !== $startDate && null !== $endDate && $endDate < $startDate) {
            throw new \InvalidArgumentException('$endDate must be greater than $startDate.');
        }

        if (null !== $startDate) {
            $queryBuilder
                ->andWhere('created_at >= :start_date')
                ->setParameter('start_date', $startDate->format('Y-m-d H:i:s'))
            ;
        }

        if (null !== $endDate) {
            $queryBuilder
                ->andWhere('created_at <= :end_date')
                ->setParameter('end_date', $endDate->format('Y-m-d H:i:s'))
            ;
        }

        return $queryBuilder;
    }

    /**
     * @param QueryBuilder    $queryBuilder
     * @param null|int|string $id
     *
     * @return QueryBuilder
     */
    private function filterByObjectId(QueryBuilder $queryBuilder, $id): QueryBuilder
    {
        if (null !== $id) {
            $queryBuilder
                ->andWhere('object_id = :object_id')
                ->setParameter('object_id', $id)
            ;
        }

        return $queryBuilder;
    }

    /**
     * @param QueryBuilder    $queryBuilder
     * @param null|int|string $id
     *
     * @return QueryBuilder
     */
    private function filterByBlameId(QueryBuilder $queryBuilder, $id): QueryBuilder
    {
        switch ($id) {
            case null:
                //no user selected
                break;
            case 'null':
                $queryBuilder
                    ->andWhere('blame_id IS NULL')
                ;
                break;
            default:
                //a specific user only
                $queryBuilder
                    ->andWhere('blame_id = :blame_id')
                    ->setParameter('blame_id', $id)
                ;
                break;
        }

        return $queryBuilder;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param string          $entity
     * @param null|int|string $id
     * @param null|int        $page
     * @param null|int        $pageSize
     * @param null|string     $transactionHash
     * @param bool            $strict
     * @param null|DateTime   $startDate
     * @param null|DateTime   $endDate
     * @param null|string     $blameId
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return QueryBuilder
     */
    private function getAuditsQueryBuilder(
        string $entity,
        $id = null,
        ?int $page = null,
        ?int $pageSize = null,
        ?string $transactionHash = null,
        bool $strict = true,
        ?DateTime $startDate = null,
        ?DateTime $endDate = null,
        ?string $blameId = null
    ): QueryBuilder {
        $this->checkAuditable($entity);
        $this->checkRoles($entity, Security::VIEW_SCOPE);

        if (null !== $page && $page < 1) {
            throw new \InvalidArgumentException('$page must be greater or equal than 1.');
        }

        if (null !== $pageSize && $pageSize < 1) {
            throw new \InvalidArgumentException('$pageSize must be greater or equal than 1.');
        }

        $storage = $this->configuration->getEntityManager() ?? $this->entityManager;
        $connection = $storage->getConnection();

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($this->getEntityAuditTableName($entity), 'at')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
        ;

        $metadata = $this->entityManager->getClassMetadata($entity);
        if ($strict && $metadata instanceof ORMMetadata && ORMMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $metadata->inheritanceType) {
            $queryBuilder
                ->andWhere('discriminator = :discriminator')
                ->setParameter('discriminator', $entity)
            ;
        }

        $this->filterByObjectId($queryBuilder, $id);
        $this->filterByType($queryBuilder, $this->filters);
        $this->filterByTransaction($queryBuilder, $transactionHash);
        $this->filterByDate($queryBuilder, $startDate, $endDate);
        $this->filterByBlameId($queryBuilder, $blameId);

        if (null !== $pageSize) {
            $queryBuilder
                ->setFirstResult(($page - 1) * $pageSize)
                ->setMaxResults($pageSize)
            ;
        }

        return $queryBuilder;
    }

    /**
     * Throws an InvalidArgumentException if given entity is not auditable.
     *
     * @param string $entity
     *
     * @throws InvalidArgumentException
     */
    private function checkAuditable(string $entity): void
    {
        if (!$this->configuration->isAuditable($entity)) {
            throw new InvalidArgumentException('Entity '.$entity.' is not auditable.');
        }
    }

    /**
     * Throws an AccessDeniedException if user not is granted to access audits for the given entity.
     *
     * @param string $entity
     * @param string $scope
     *
     * @throws AccessDeniedException
     */
    private function checkRoles(string $entity, string $scope): void
    {
        $userProvider = $this->configuration->getUserProvider();
        $user = null === $userProvider ? null : $userProvider->getUser();
        $security = null === $userProvider ? null : $userProvider->getSecurity();

        if (!($user instanceof UserInterface) || !($security instanceof CoreSecurity)) {
            // If no security defined or no user identified, consider access granted
            return;
        }

        $entities = $this->configuration->getEntities();

        $roles = $entities[$entity]['roles'] ?? null;

        if (null === $roles) {
            // If no roles are configured, consider access granted
            return;
        }

        $scope = $roles[$scope] ?? null;

        if (null === $scope) {
            // If no roles for the given scope are configured, consider access granted
            return;
        }

        // roles are defined for the give scope
        foreach ($scope as $role) {
            if ($security->isGranted($role)) {
                // role granted => access granted
                return;
            }
        }

        // access denied
        throw new AccessDeniedException('You are not allowed to access audits of '.$entity.' entity.');
    }
}
