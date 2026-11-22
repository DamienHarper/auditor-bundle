<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Controller;

use DH\Auditor\Attribute\Security;
use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\JsonPlatformHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\NullFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\AuditorBundle\Enum\EntitySort;
use DH\AuditorBundle\Helper\UrlHelper;
use DH\AuditorBundle\Tests\Controller\ViewerControllerTest;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use DH\AuditorBundle\Viewer\RevisionComparator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException as SymfonyAccessDeniedException;
use Twig\Environment;

/**
 * @see ViewerControllerTest
 */
#[AsController]
final readonly class ViewerController
{
    public function __construct(
        private Environment $environment,
        private ?ActivityGraphProvider $activityGraphProvider = null,
    ) {}

    #[Route(path: '/audit', name: 'dh_auditor_list_audits', methods: ['GET'])]
    public function listAuditsAction(Request $request, Reader $reader): Response
    {
        $sort = EntitySort::fromQueryParam($request->query->get('sort'));

        $schemaManager = new SchemaManager($reader->getProvider());

        /** @var AuditingService[] $auditingServices */
        $auditingServices = $reader->getProvider()->getAuditingServices();
        $audited = [];
        $scope = Security::VIEW_SCOPE;
        foreach ($auditingServices as $auditingService) {
            $entities = array_filter(
                $schemaManager->getAuditableTableNames($auditingService->getEntityManager()),
                static function (string $entity) use ($reader, $scope): bool {
                    $roleChecker = $reader->getProvider()->getAuditor()->getConfiguration()->getRoleChecker();

                    return null === $roleChecker || (bool) $roleChecker($entity, $scope);
                },
                ARRAY_FILTER_USE_KEY
            );

            foreach ($entities as $entity => $table) {
                $query = $reader->createQuery($entity, ['page_size' => 1]);
                $count = $query->count();

                if ($count > 0) {
                    $latest = $query->execute()[0] ?? null;
                    $audited[$entity] = [
                        'table' => $table,
                        'count' => $count,
                        'latest' => $latest,
                    ];
                }
            }
        }

        // Activity Graph
        $activityGraphEnabled = $this->activityGraphProvider instanceof ActivityGraphProvider;
        $activityGraphDays = 30;
        $activityGraphLayout = 'bottom';
        if ($activityGraphEnabled) {
            $activityGraphProvider = $this->activityGraphProvider;
            $activityGraphDays = $activityGraphProvider->days;
            $activityGraphLayout = $activityGraphProvider->layout;
            foreach ($audited as $entity => &$data) {
                $activityData = $activityGraphProvider->getActivityDataWithRaw($entity, $reader);
                $data['activityGraph'] = $activityData['normalized'];
                $data['activityGraphRaw'] = $activityData['raw'];
                $data['activityGraphDates'] = $activityData['dates'];
            }

            unset($data);
        }

        $audited = $this->sortAudited($audited, $sort);

        return $this->renderView('@DHAuditor/Audit/audits.html.twig', [
            'audited' => $audited,
            'reader' => $reader,
            'sort' => $sort->value,
            'sortOptions' => EntitySort::cases(),
            'activityGraphEnabled' => $activityGraphEnabled,
            'activityGraphDays' => $activityGraphDays,
            'activityGraphLayout' => $activityGraphLayout,
        ]);
    }

    #[Route(path: '/audit/transaction/{hash}', name: 'dh_auditor_show_transaction_stream', methods: ['GET'])]
    public function showTransactionAction(Reader $reader, string $hash): Response
    {
        $audits = $reader->getAuditsByTransactionId($hash);

        return $this->renderView('@DHAuditor/Audit/transaction_stream.html.twig', [
            'hash' => $hash,
            'audits' => $audits,
        ]);
    }

    /**
     * Single chronological timeline of everything a user did, across every audited entity the
     * current viewer is allowed to see (entities denied by the role checker are silently skipped).
     *
     * The user is identified by the `blame_id` column. Each entity is queried for at most
     * `page * pageSize` rows (newest first), so only the rows needed to build the requested page
     * are loaded, whatever the size of the audit tables.
     */
    #[Route(path: '/audit/user/{id}', name: 'dh_auditor_show_user_stream', requirements: ['id' => '.{1,255}'], methods: ['GET'])]
    public function showUserStreamAction(Request $request, Reader $reader, string $id): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        /** @var Configuration $configuration */
        $configuration = $reader->getProvider()->getConfiguration();
        $pageSize = $configuration->getViewerPageSize();

        /** @var array<string, Query> $queries */
        $queries = [];
        $numResults = 0;
        foreach (array_keys($configuration->getEntities()) as $entity) {
            try {
                $query = $reader->createQuery($entity, ['blame_id' => $id, 'page_size' => null]);
            } catch (AccessDeniedException) {
                continue;
            }

            $count = $query->count();
            if ($count > 0) {
                $queries[$entity] = $query;
                $numResults += $count;
            }
        }

        $numPages = (int) ceil($numResults / $pageSize);
        $page = min($page, max(1, $numPages));

        // The newest `page * pageSize` rows of each entity are enough to build the requested page.
        $entries = [];
        foreach ($queries as $entity => $query) {
            foreach ($query->limit($page * $pageSize)->execute() as $entry) {
                $entries[] = ['entity' => $entity, 'entry' => $entry];
            }
        }

        // usort() is stable: rows of a same entity keep the (created_at, id) order of their query.
        usort($entries, static fn (array $a, array $b): int => $b['entry']->createdAt <=> $a['entry']->createdAt);

        return $this->renderView('@DHAuditor/Audit/user_stream.html.twig', [
            'id' => $id,
            // the username recorded with the most recent entry (it may have changed over time)
            'username' => [] !== $entries ? $entries[0]['entry']->username : null,
            'entries' => \array_slice($entries, ($page - 1) * $pageSize, $pageSize),
            'paginator' => [
                'currentPage' => $page,
                'hasPreviousPage' => $page > 1,
                'hasNextPage' => ($page * $pageSize) < $numResults,
                'previousPage' => $page > 1 ? $page - 1 : null,
                'nextPage' => ($page * $pageSize) < $numResults ? $page + 1 : null,
                'numPages' => $numPages,
                'haveToPaginate' => $numResults > $pageSize,
                'numResults' => $numResults,
                'pageSize' => $pageSize,
            ],
        ]);
    }

    #[Route(path: '/audit/{entity}/{id}', name: 'dh_auditor_show_entity_stream', methods: ['GET'])]
    public function showEntityStreamAction(Request $request, Reader $reader, string $entity, int|string|null $id = null): Response
    {
        $page = $request->query->getInt('page', 1);
        $page = max(1, $page);

        // Get filter parameters
        $typeFilter = $request->query->get('type');
        $userFilter = $request->query->get('user');
        $dateFromFilter = $request->query->getString('date_from');
        $dateToFilter = $request->query->getString('date_to');
        $fieldFilter = $request->query->get('field');

        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        try {
            /** @var Configuration $configuration */
            $configuration = $reader->getProvider()->getConfiguration();
            $pageSize = $configuration->getViewerPageSize();

            // Build query options with filters
            $queryOptions = [
                'object_id' => $id,
                'page' => $page,
                'page_size' => $pageSize,
            ];

            if (null !== $typeFilter && '' !== $typeFilter) {
                $queryOptions['type'] = $typeFilter;
            }

            // Handle user filter - special case for anonymous
            $isAnonymousFilter = '__anonymous__' === $userFilter;
            if (null !== $userFilter && '' !== $userFilter && !$isAnonymousFilter) {
                $queryOptions['blame_id'] = $userFilter;
            }

            if (null !== $fieldFilter && '' !== $fieldFilter) {
                $queryOptions['changed_field'] = $fieldFilter;
            }

            try {
                $query = $reader->createQuery($entity, $queryOptions);
            } catch (InvalidArgumentException $e) {
                throw new BadRequestHttpException($e->getMessage(), $e);
            }

            $timezone = new \DateTimeZone($reader->getProvider()->getAuditor()->getConfiguration()->timezone);
            $dateFrom = $this->parseDateFilter($dateFromFilter, $timezone);
            $dateTo = $this->parseDateFilter($dateToFilter, $timezone)?->setTime(23, 59, 59);
            if ($dateFrom instanceof \DateTimeImmutable && $dateTo instanceof \DateTimeImmutable && $dateFrom > $dateTo) {
                throw new BadRequestHttpException('The start date must not be later than the end date.');
            }

            if ($dateFrom instanceof \DateTimeImmutable || $dateTo instanceof \DateTimeImmutable) {
                $query->addFilter(new DateRangeFilter(Query::CREATED_AT, $dateFrom, $dateTo));
            }

            // Add NullFilter for anonymous users
            if ($isAnonymousFilter) {
                $query->addFilter(new NullFilter(Query::USER_ID));
            }

            // Get available filter options (types, users and changed fields)
            $filterOptions = $this->getFilterOptions($reader, $entity, $id);

            if (isset($queryOptions['changed_field'])) {
                if ([] === $filterOptions['fields']) {
                    throw new BadRequestHttpException('Filtering on a changed field is not available for this audit table.');
                }

                // Keep a field that is no longer mapped (renamed, removed) selectable.
                if (!\in_array($queryOptions['changed_field'], $filterOptions['fields'], true)) {
                    $filterOptions['fields'][] = $queryOptions['changed_field'];
                    sort($filterOptions['fields']);
                }
            }

            $pager = $reader->paginate($query, $page, $pageSize);
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        return $this->renderView('@DHAuditor/Audit/entity_stream.html.twig', [
            'id' => $id,
            'entity' => $entity,
            'paginator' => $pager,
            'filters' => [
                'type' => $typeFilter,
                'user' => $userFilter,
                'date_from' => $dateFromFilter,
                'date_to' => $dateToFilter,
                'field' => $fieldFilter,
            ],
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Lists the revisions of an audited object so that two of them can be compared: the first
     * one picked becomes the baseline (?selected=), picking a second one opens the comparison.
     */
    #[Route(path: '/audit/{entity}/{id}/revisions', name: 'dh_auditor_select_revisions', methods: ['GET'])]
    public function selectRevisionsAction(Request $request, Reader $reader, string $entity, int|string $id): Response
    {
        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $selectedId = $this->parseRevisionId($request, 'selected');

        try {
            /** @var Configuration $configuration */
            $configuration = $reader->getProvider()->getConfiguration();
            $pageSize = $configuration->getViewerPageSize();

            $query = $reader->createQuery($entity, ['object_id' => $id, 'page' => $page, 'page_size' => $pageSize]);
            $pager = $reader->paginate($query, $page, $pageSize);

            $selected = null;
            if (null !== $selectedId) {
                $selected = $this->createRevisionQuery($reader, $entity, $id)
                    ->addFilter(new SimpleFilter(Query::ID, $selectedId))
                    ->execute()[0] ?? null
                ;

                // The baseline must be a revision of this very object.
                if (null === $selected) {
                    throw new NotFoundHttpException('Revision not found.');
                }
            }
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        return $this->renderView('@DHAuditor/Audit/revisions.html.twig', [
            'entity' => $entity,
            'id' => $id,
            'paginator' => $pager,
            'selected' => $selected,
        ]);
    }

    /**
     * Shows what changed on an audited object between two of its revisions.
     *
     * Only the entries recorded between the two revisions are loaded and composed (see
     * RevisionComparator): the result stays exact when older entries have been purged.
     */
    #[Route(path: '/audit/{entity}/{id}/compare', name: 'dh_auditor_compare_revisions', methods: ['GET'])]
    public function compareRevisionsAction(Request $request, Reader $reader, string $entity, int|string $id): Response
    {
        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        $fromId = $this->parseRevisionId($request, 'from');
        $toId = $this->parseRevisionId($request, 'to');

        if (null === $fromId || null === $toId) {
            throw new BadRequestHttpException('Both "from" and "to" revisions are required.');
        }

        // Always compare in chronological order, whichever revision was picked first.
        [$fromId, $toId] = [min($fromId, $toId), max($fromId, $toId)];

        try {
            // Audit entry IDs grow with time within an audit table: they give the chronological order.
            $range = array_values($this->createRevisionQuery($reader, $entity, $id)
                ->addFilter(new RangeFilter(Query::ID, $fromId, $toId))
                ->execute());
            $before = $this->createRevisionQuery($reader, $entity, $id, 'DESC')
                ->addFilter(new RangeFilter(Query::ID, null, $fromId - 1))
                ->limit(1)
                ->execute()[0] ?? null
            ;
            $after = $this->createRevisionQuery($reader, $entity, $id)
                ->addFilter(new RangeFilter(Query::ID, $toId + 1))
                ->limit(1)
                ->execute()[0] ?? null
            ;
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        $from = $range[0] ?? null;
        $to = [] === $range ? null : $range[\count($range) - 1];

        // Both revisions must exist and belong to this very object.
        if (null === $from || null === $to || $from->id !== $fromId || $to->id !== $toId) {
            throw new NotFoundHttpException('Revision not found.');
        }

        $count = \count($range);

        // "Previous" and "next" shift both revisions by one, so users can step through history.
        $previous = null === $before ? null : [
            'from' => $before->id,
            'to' => $count > 1 ? $range[$count - 2]->id : $before->id,
        ];
        $next = null === $after ? null : [
            'from' => $count > 1 ? $range[1]->id : $after->id,
            'to' => $after->id,
        ];

        return $this->renderView('@DHAuditor/Audit/compare.html.twig', [
            'entity' => $entity,
            'id' => $id,
            'from' => $from,
            'to' => $to,
            'comparison' => new RevisionComparator()->compare(\array_slice($range, 1)),
            'previous' => $previous,
            'next' => $next,
        ]);
    }

    private function parseDateFilter(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new BadRequestHttpException('Dates must use the YYYY-MM-DD format.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('Dates must be valid calendar dates in YYYY-MM-DD format.');
        }

        return $date;
    }

    /**
     * Returns the audit entry ID passed in the query string, or null when it is absent.
     */
    private function parseRevisionId(Request $request, string $name): ?int
    {
        $value = $request->query->getString($name);

        if ('' === $value) {
            return null;
        }

        if (1 !== preg_match('/^[1-9]\d{0,17}$/D', $value)) {
            throw new BadRequestHttpException(\sprintf('The "%s" revision must be a positive integer.', $name));
        }

        return (int) $value;
    }

    /**
     * Query on the revisions of a single object, ordered by audit entry ID.
     */
    private function createRevisionQuery(Reader $reader, string $entity, int|string $id, string $direction = 'ASC'): Query
    {
        return $reader->createQuery($entity, ['object_id' => $id, 'page_size' => null])
            ->resetOrderBy()
            ->addOrderBy(Query::ID, $direction)
        ;
    }

    /**
     * Sorts the audited entities; ties on event count or latest activity are broken by name (A-Z).
     *
     * @template T of array{count: int, latest: ?Entry}
     *
     * @param array<array-key, T> $audited audit summaries indexed by entity FQCN
     *
     * @return array<array-key, T>
     */
    private function sortAudited(array $audited, EntitySort $sort): array
    {
        uksort($audited, function (int|string $a, int|string $b) use ($audited, $sort): int {
            $byName = strcasecmp($this->shortEntityName((string) $a), $this->shortEntityName((string) $b)) ?: strcmp((string) $a, (string) $b);

            return match ($sort) {
                EntitySort::NameAsc => $byName,
                EntitySort::NameDesc => -$byName,
                EntitySort::CountAsc => ($audited[$a]['count'] <=> $audited[$b]['count']) ?: $byName,
                EntitySort::CountDesc => ($audited[$b]['count'] <=> $audited[$a]['count']) ?: $byName,
                EntitySort::ActivityAsc => ($this->latestActivityTimestamp($audited[$a]['latest']) <=> $this->latestActivityTimestamp($audited[$b]['latest'])) ?: $byName,
                EntitySort::ActivityDesc => ($this->latestActivityTimestamp($audited[$b]['latest']) <=> $this->latestActivityTimestamp($audited[$a]['latest'])) ?: $byName,
            };
        });

        return $audited;
    }

    private function shortEntityName(string $entity): string
    {
        $position = mb_strrpos($entity, '\\');

        return false === $position ? $entity : mb_substr($entity, $position + 1);
    }

    private function latestActivityTimestamp(?Entry $latest): int
    {
        return $latest?->createdAt?->getTimestamp() ?? 0;
    }

    /**
     * Get available filter options (distinct types, users and changed fields) for an entity.
     *
     * Supports both schema v1 (legacy: blame_user column) and schema v2 (blame JSON column).
     *
     * @return array{types: array<string>, users: array<array{id: string, name: string}>, hasAnonymous: bool, fields: array<string>}
     */
    private function getFilterOptions(Reader $reader, string $entity, int|string|null $id): array
    {
        $storageService = $reader->getProvider()->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();
        $auditTable = $reader->getEntityAuditTableName($entity);

        // Introspect the actual table structure to detect the schema version.
        // v2 tables have a 'blame' JSON column; v1 (legacy) tables use separate 'blame_user' columns.
        $tableSchema = $connection->createSchemaManager()->introspectSchema()->getTable($auditTable);
        $hasBlameColumn = $tableSchema->hasColumn('blame');      // schema v2
        $hasLegacyBlame = $tableSchema->hasColumn('blame_user'); // schema v1 (legacy)

        // Build base condition for object_id if provided
        $params = [];
        $objectIdCondition = '';
        if (null !== $id) {
            $objectIdCondition = 'object_id = :object_id';
            $params['object_id'] = $id;
        }

        // Get distinct types
        $typesSql = \sprintf(
            'SELECT DISTINCT type FROM %s %s ORDER BY type',
            $auditTable,
            '' !== $objectIdCondition ? 'WHERE '.$objectIdCondition : ''
        );

        /** @var list<string> $types */
        $types = $connection->executeQuery($typesSql, $params)->fetchFirstColumn();

        $users = [];

        if ($hasBlameColumn) {
            // Schema v2: username is encoded as JSON in the 'blame' column, which no index can cover.
            // Only the latest row of each user is read: MAX(id) per blame_id is resolved from the
            // blame_id index alone, then the blame of those rows is fetched by primary key (a derived
            // table rather than IN (subquery), which MySQL would run as a full scan).
            // A blame also holds the IP and firewall: one user is listed once, under their latest username.
            $usersSql = \sprintf(
                'SELECT a.blame_id, a.blame FROM %1$s a INNER JOIN (SELECT MAX(id) AS id FROM %1$s WHERE blame_id IS NOT NULL %2$s GROUP BY blame_id) latest ON a.id = latest.id',
                $auditTable,
                '' !== $objectIdCondition ? 'AND '.$objectIdCondition : ''
            );

            $usersResult = $connection->executeQuery($usersSql, $params)->fetchAllAssociative();
            $users = array_map(static function (array $row): array {
                $blameId = $row['blame_id'];
                $blameUser = null;

                if (\is_string($row['blame'] ?? null)) {
                    $decoded = json_decode($row['blame'], true);
                    $blameUser = \is_array($decoded) ? ($decoded['username'] ?? null) : null;
                }

                return [
                    'id' => \is_scalar($blameId) ? (string) $blameId : '',
                    'name' => \is_string($blameUser) ? $blameUser : (\is_scalar($blameId) ? (string) $blameId : ''),
                ];
            }, $usersResult);
            usort($users, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);
        } elseif ($hasLegacyBlame) {
            // Schema v1 (legacy): username is stored in the dedicated 'blame_user' column.
            $usersSql = \sprintf(
                'SELECT DISTINCT blame_id, blame_user FROM %s WHERE blame_id IS NOT NULL %s ORDER BY blame_id',
                $auditTable,
                '' !== $objectIdCondition ? 'AND '.$objectIdCondition : ''
            );

            $usersResult = $connection->executeQuery($usersSql, $params)->fetchAllAssociative();
            $users = array_map(static function (array $row): array {
                $blameId = $row['blame_id'];
                $blameUser = isset($row['blame_user']) && '' !== $row['blame_user'] ? $row['blame_user'] : null;

                return [
                    'id' => \is_scalar($blameId) ? (string) $blameId : '',
                    'name' => \is_string($blameUser) ? $blameUser : (\is_scalar($blameId) ? (string) $blameId : ''),
                ];
            }, $usersResult);
        }

        // If neither column exists, $users remains [] (no blame info available).

        // Check if there are anonymous entries (blame_id IS NULL): the first one is enough
        $anonymousSql = $connection->getDatabasePlatform()->modifyLimitQuery(\sprintf(
            'SELECT 1 FROM %s WHERE blame_id IS NULL %s',
            $auditTable,
            '' !== $objectIdCondition ? 'AND '.$objectIdCondition : ''
        ), 1);
        $hasAnonymous = false !== $connection->executeQuery($anonymousSql, $params)->fetchOne();

        // The changed field filter needs native JSON support and the schema v2 structure
        // (schema_version column); legacy tables must be migrated first.
        $fields = $tableSchema->hasColumn('schema_version') && JsonPlatformHelper::isJsonSearchSupported($connection)
            ? $this->getAuditedFields($reader, $entity)
            : [];

        return [
            'types' => $types,
            'users' => $users,
            'hasAnonymous' => $hasAnonymous,
            'fields' => $fields,
        ];
    }

    /**
     * Lists the fields of an entity that may appear in its audit entries, read from the Doctrine
     * mapping rather than from the audit table: fields and associations, minus the ignored
     * columns and the generated identifiers.
     *
     * @return list<string>
     */
    private function getAuditedFields(Reader $reader, string $entity): array
    {
        if (!class_exists($entity)) {
            return [];
        }

        /** @var Configuration $configuration */
        $configuration = $reader->getProvider()->getConfiguration();
        $ignoredColumns = $configuration->getIgnoredColumns();
        $entityConfiguration = $configuration->getEntities()[$entity] ?? null;
        if (\is_array($entityConfiguration) && \is_array($entityConfiguration['ignored_columns'] ?? null)) {
            $ignoredColumns = [...$ignoredColumns, ...array_filter($entityConfiguration['ignored_columns'], \is_string(...))];
        }

        $metadata = $reader->getProvider()->getAuditingServiceForEntity($entity)->getEntityManager()->getClassMetadata($entity);
        $generatedIdentifiers = $metadata->isIdentifierNatural() ? [] : $metadata->getIdentifierFieldNames();

        $fields = array_values(array_diff(
            [...array_keys($metadata->fieldMappings), ...array_keys($metadata->associationMappings)],
            $ignoredColumns,
            $generatedIdentifiers,
        ));
        sort($fields);

        return $fields;
    }

    private function renderView(string $view, array $parameters = []): Response
    {
        return new Response($this->environment->render($view, $parameters));
    }
}
