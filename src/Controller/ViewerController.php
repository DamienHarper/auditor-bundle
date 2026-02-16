<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Controller;

use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Security;
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
        if ($activityGraphEnabled && $this->activityGraphProvider instanceof ActivityGraphProvider) {
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
        $audits = $reader->getAuditsByTransactionHash($hash);

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

    /**
     * Get available filter options (distinct types and users) for an entity.
     *
     * @return array{types: array<string>, users: array<array{id: string, name: string}>, hasAnonymous: bool}
     */
    private function getFilterOptions(Reader $reader, string $entity, int|string|null $id): array
    {
        $storageService = $reader->getProvider()->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();
        $auditTable = $reader->getEntityAuditTableName($entity);

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

        // Get distinct users (blame_id and blame_user) - excluding NULL
        $usersSql = \sprintf(
            'SELECT DISTINCT blame_id, blame_user FROM %s WHERE blame_id IS NOT NULL %s ORDER BY blame_user',
            $auditTable,
            '' !== $objectIdCondition ? 'AND '.$objectIdCondition : ''
        );

        $usersResult = $connection->executeQuery($usersSql, $params)->fetchAllAssociative();
        $users = array_map(static function (array $row): array {
            $blameId = $row['blame_id'];
            $blameUser = $row['blame_user'] ?? $blameId;

            return [
                'id' => \is_scalar($blameId) ? (string) $blameId : '',
                'name' => \is_scalar($blameUser) ? (string) $blameUser : '',
            ];
        }, $usersResult);

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
