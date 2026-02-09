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
final class ViewerController
{
    public function __construct(private readonly Environment $environment) {}

    #[Route(path: '/audit', name: 'dh_auditor_list_audits', methods: ['GET'])]
    public function listAuditsAction(Reader $reader): Response
    {
        $schemaManager = new SchemaManager($reader->getProvider());

        /** @var AuditingService[] $auditingServices */
        $auditingServices = $reader->getProvider()->getAuditingServices();
        $audited = [];
        $scope = Security::VIEW_SCOPE;
        foreach ($auditingServices as $auditingService) {
            $audited = array_merge(
                $audited,
                array_filter(
                    $schemaManager->getAuditableTableNames($auditingService->getEntityManager()),
                    static function (string $entity) use ($reader, $scope) {
                        $roleChecker = $reader->getProvider()->getAuditor()->getConfiguration()->getRoleChecker();

                        return null === $roleChecker ? true : $roleChecker($entity, $scope);
                    },
                    ARRAY_FILTER_USE_KEY
                )
            );
        }

        return $this->renderView('@DHAuditor/Audit/audits.html.twig', [
            'audited' => $audited,
            'reader' => $reader,
        ]);
    }

    #[Route(path: '/audit/transaction/{hash}', name: 'dh_auditor_show_transaction', methods: ['GET'])]
    public function showTransactionAction(Reader $reader, string $hash): Response
    {
        $audits = $reader->getAuditsByTransactionHash($hash);

        return $this->renderView('@DHAuditor/Audit/transaction.html.twig', [
            'hash' => $hash,
            'audits' => $audits,
        ]);
    }

    #[Route(path: '/audit/{entity}/{id}', name: 'dh_auditor_show_entity_history', methods: ['GET'])]
    public function showEntityHistoryAction(Request $request, Reader $reader, string $entity, int|string|null $id = null): Response
    {
        \assert(\is_string($request->query->get('page', '1')));
        $page = (int) $request->query->get('page', '1');
        $page = max(1, $page);

        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        try {
            /** @var Configuration $configuration */
            $configuration = $reader->getProvider()->getConfiguration();
            $pageSize = $configuration->getViewerPageSize();
            $pager = $reader->paginate($reader->createQuery($entity, [
                'object_id' => $id,
                'page' => $page,
                'page_size' => $pageSize,
            ]), $page, $pageSize);
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        return $this->renderView('@DHAuditor/Audit/entity_history.html.twig', [
            'id' => $id,
            'entity' => $entity,
            'paginator' => $pager,
        ]);
    }

    private function renderView(string $view, array $parameters = []): Response
    {
        return new Response($this->environment->render($view, $parameters));
    }
}
