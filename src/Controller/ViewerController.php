<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Controller;

use DH\Auditor\Attribute\Security;
use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\NullFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\AuditorBundle\Helper\UrlHelper;
use DH\AuditorBundle\Tests\Controller\ViewerControllerTest;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
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
    public function listAuditsAction(Reader $reader): Response
    {
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
            }

            unset($data);
        }

        return $this->renderView('@DHAuditor/Audit/audits.html.twig', [
            'audited' => $audited,
            'reader' => $reader,
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

    #[Route(path: '/audit/{entity}/{id}', name: 'dh_auditor_show_entity_stream', methods: ['GET'])]
    public function showEntityStreamAction(Request $request, Reader $reader, string $entity, int|string|null $id = null): Response
    {
        $page = $request->query->getInt('page', 1);
        $page = max(1, $page);

        // Get filter parameters
        $typeFilter = $request->query->get('type');
        $userFilter = $request->query->get('user');

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

            $query = $reader->createQuery($entity, $queryOptions);

            // Add NullFilter for anonymous users
            if ($isAnonymousFilter) {
                $query->addFilter(new NullFilter(Query::USER_ID));
            }

            $pager = $reader->paginate($query, $page, $pageSize);

            // Get available filter options (types and users)
            $filterOptions = $this->getFilterOptions($reader, $entity, $id);
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
            ],
            'filterOptions' => $filterOptions,
        ]);
    }

    #[Route(path: '/audit/{entity}/{id}/revisions', name: 'dh_auditor_select_revisions', methods: ['GET'])]
    public function selectRevisionsAction(Reader $reader, string $entity, int|string $id): Response
    {
        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        try {
            $entries = $reader->createQuery($entity, ['object_id' => $id, 'page_size' => null])->execute();
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        return $this->renderView('@DHAuditor/Audit/revisions.html.twig', [
            'entity' => $entity,
            'id' => $id,
            'entries' => $entries,
        ]);
    }

    #[Route(path: '/audit/{entity}/{id}/compare', name: 'dh_auditor_compare_revisions', methods: ['GET'])]
    public function compareRevisionsAction(Request $request, Reader $reader, string $entity, int|string $id): Response
    {
        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        $fromId = $request->query->get('from');
        $toId = $request->query->get('to');

        if (null === $fromId || null === $toId) {
            throw new NotFoundHttpException('Both "from" and "to" revisions are required.');
        }

        try {
            // Reader returns newest first; reconstructing state needs chronological
            // (oldest first) order, so the whole history is fetched and reversed.
            $entries = array_values(array_reverse($reader->createQuery($entity, ['object_id' => $id, 'page_size' => null])->execute()));
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        $fromIndex = $this->findRevisionIndex($entries, $fromId);
        $toIndex = $this->findRevisionIndex($entries, $toId);

        if (null === $fromIndex || null === $toIndex) {
            throw new NotFoundHttpException('Revision not found.');
        }

        // Always diff in chronological order, regardless of which one was picked first.
        if ($fromIndex > $toIndex) {
            [$fromIndex, $toIndex] = [$toIndex, $fromIndex];
        }

        $stateFrom = $this->reconstructState($entries, $fromIndex);
        $stateTo = $this->reconstructState($entries, $toIndex);

        $diff = [];
        foreach (array_unique([...array_keys($stateFrom), ...array_keys($stateTo)]) as $field) {
            $old = $stateFrom[$field] ?? null;
            $new = $stateTo[$field] ?? null;

            if ($old !== $new) {
                $diff[$field] = ['old' => $old, 'new' => $new];
            }
        }
        ksort($diff);

        return $this->renderView('@DHAuditor/Audit/compare.html.twig', [
            'entity' => $entity,
            'id' => $id,
            'from' => $entries[$fromIndex],
            'to' => $entries[$toIndex],
            'diff' => $diff,
        ]);
    }

    /**
     * Finds the position of the audit entry whose ID matches $revisionId, within entries sorted chronologically.
     *
     * @param list<Entry> $entries
     */
    private function findRevisionIndex(array $entries, string $revisionId): ?int
    {
        foreach ($entries as $index => $entry) {
            if ((string) $entry->id === $revisionId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Reconstructs the object's field values by replaying every entry up to and including $uptoIndex.
     *
     * Association entries (no business field) and nested JSON sub-diffs are not replayed:
     * only top-level scalar/array field changes are tracked.
     *
     * @param list<Entry> $entriesAscending
     *
     * @return array<string, mixed>
     */
    private function reconstructState(array $entriesAscending, int $uptoIndex): array
    {
        $state = [];

        for ($i = 0; $i <= $uptoIndex; ++$i) {
            foreach ($entriesAscending[$i]->getDiffs() as $field => $changeset) {
                $field = (string) $field;

                if ('label' === $field || !\is_array($changeset)) {
                    continue;
                }

                if (\array_key_exists('new', $changeset)) {
                    $state[$field] = $changeset['new'];
                } elseif (\array_key_exists('old', $changeset)) {
                    // REMOVE snapshot: 'old' is the last known value before deletion.
                    $state[$field] = $changeset['old'];
                }
            }
        }

        return $state;
    }

    /**
     * Get available filter options (distinct types and users) for an entity.
     *
     * Supports both schema v1 (legacy: blame_user column) and schema v2 (blame JSON column).
     *
     * @return array{types: array<string>, users: array<array{id: string, name: string}>, hasAnonymous: bool}
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
            // Schema v2: username is encoded as JSON in the 'blame' column.
            $usersSql = \sprintf(
                'SELECT DISTINCT blame_id, blame FROM %s WHERE blame_id IS NOT NULL %s ORDER BY blame_id',
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

        // Check if there are anonymous entries (blame_id IS NULL)
        $anonymousSql = \sprintf(
            'SELECT COUNT(*) FROM %s WHERE blame_id IS NULL %s',
            $auditTable,
            '' !== $objectIdCondition ? 'AND '.$objectIdCondition : ''
        );
        $anonymousCount = $connection->executeQuery($anonymousSql, $params)->fetchOne();
        $hasAnonymous = is_numeric($anonymousCount) && (int) $anonymousCount > 0;

        return [
            'types' => $types,
            'users' => $users,
            'hasAnonymous' => $hasAnonymous,
        ];
    }

    private function renderView(string $view, array $parameters = []): Response
    {
        return new Response($this->environment->render($view, $parameters));
    }
}
