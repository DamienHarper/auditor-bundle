<?php

namespace DH\DoctrineAuditBundle\Controller;

use DH\DoctrineAuditBundle\Exception\AccessDeniedException;
use DH\DoctrineAuditBundle\Exception\InvalidArgumentException;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuditController extends AbstractController
{
    /**
     * @Route("/audit", name="dh_doctrine_audit_list_audits", methods={"GET"})
     *
     * @param Request $request
     *
     * @return Response
     */
    public function listAuditsAction(Request $request): Response
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');

        return $this->render(
            '@DHDoctrineAudit/Audit/audits.html.twig', [
                'audited' => $reader->getEntities($request->query->get('blame')),
                'blamed' => $reader->getBlames(),
                'reader' => $reader,
            ]);
    }

    /**
     * @Route("/audit/transaction/{hash}", name="dh_doctrine_audit_show_transaction", methods={"GET"})
     *
     * @param string $hash
     *
     * @throws InvalidArgumentException
     * @throws \Doctrine\ORM\ORMException
     *
     * @return Response
     */
    public function showTransactionAction(string $hash): Response
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $audits = $reader->getAuditsByTransactionHash($hash);

        return $this->render('@DHDoctrineAudit/Audit/transaction.html.twig', [
            'hash' => $hash,
            'audits' => $audits,
        ]);
    }

    /**
     * @Route("/audit/{entity}/{id}", name="dh_doctrine_audit_show_entity_history", methods={"GET"})
     *
     * @param Request    $request
     * @param string     $entity
     * @param int|string $id
     *
     * @throws InvalidArgumentException
     *
     * @return Response
     */
    public function showEntityHistoryAction(Request $request, string $entity, $id = null): Response
    {
        $page = (int) $request->query->get('page', 1);
        $blame = $request->query->get('blame');
        $entity = AuditHelper::paramToNamespace($entity);

        $reader = $this->container->get('dh_doctrine_audit.reader');

        if (!$reader->getConfiguration()->isAuditable($entity)) {
            throw $this->createNotFoundException();
        }

        try {
            $entries = $reader->getAuditsPager(
                $entity,
                $id,
                $page,
                AuditReader::PAGE_SIZE,
                null,
                true,
                null,
                null,
                $blame
            );
        } catch (AccessDeniedException $e) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@DHDoctrineAudit/Audit/entity_history.html.twig', [
            'id' => $id,
            'entity' => $entity,
            'blamed' => $reader->getBlame($entity),
            'paginator' => $entries,
        ]);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'dh_doctrine_audit.reader' => AuditReader::class,
        ]);
    }
}
