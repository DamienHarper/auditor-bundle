<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Controller;

use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Provider\Doctrine\Auditing\Annotation\Security;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Schema\SchemaManager;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\AuditorBundle\Helper\UrlHelper;
use DH\AuditorBundle\Tests\Controller\ViewerControllerTest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException as SymfonyAccessDeniedException;
use Twig\Environment;

/**
 * @see ViewerControllerTest
 */
final readonly class ViewerController
{
    public function __construct(private Environment $environment) {}

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

        return $this->renderView('@DHAuditor/Audit/audits.html.twig', [
            'audited' => $audited,
            'reader' => $reader,
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
    public function showEntityHistoryAction(Request $request, Reader $reader, string $entity, int|string|null $id = null): Response
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

            if (null !== $userFilter && '' !== $userFilter) {
                $queryOptions['blame_id'] = $userFilter;
            }

            $pager = $reader->paginate($reader->createQuery($entity, $queryOptions), $page, $pageSize);

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
     * @return array{types: array<string>, users: array<array{id: string, name: string}>}
     */
    private function getFilterOptions(Reader $reader, string $entity, int|string|null $id): array
    {
        $storageService = $reader->getProvider()->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();
        $auditTable = $reader->getEntityAuditTableName($entity);

        // Build base condition for object_id if provided
        $whereClause = '';
        $params = [];
        if (null !== $id) {
            $whereClause = 'WHERE object_id = :object_id';
            $params['object_id'] = $id;
        }

        // Get distinct types
        $typesSql = \sprintf('SELECT DISTINCT type FROM %s %s ORDER BY type', $auditTable, $whereClause);
        $types = $connection->executeQuery($typesSql, $params)->fetchFirstColumn();

        // Get distinct users (blame_id and blame_user)
        $usersSql = \sprintf(
            'SELECT DISTINCT blame_id, blame_user FROM %s %s WHERE blame_id IS NOT NULL ORDER BY blame_user',
            $auditTable,
            '' !== $whereClause ? $whereClause.' AND' : 'WHERE'
        );
        // Fix the SQL - we need to handle the WHERE properly
        if (null !== $id) {
            $usersSql = \sprintf(
                'SELECT DISTINCT blame_id, blame_user FROM %s WHERE object_id = :object_id AND blame_id IS NOT NULL ORDER BY blame_user',
                $auditTable
            );
        } else {
            $usersSql = \sprintf(
                'SELECT DISTINCT blame_id, blame_user FROM %s WHERE blame_id IS NOT NULL ORDER BY blame_user',
                $auditTable
            );
        }

        $usersResult = $connection->executeQuery($usersSql, $params)->fetchAllAssociative();
        $users = array_map(static fn (array $row): array => [
            'id' => $row['blame_id'],
            'name' => $row['blame_user'] ?? $row['blame_id'],
        ], $usersResult);

        return [
            'types' => $types,
            'users' => $users,
        ];
    }

    private function renderView(string $view, array $parameters = []): Response
    {
        return new Response($this->environment->render($view, $parameters));
    }
}
