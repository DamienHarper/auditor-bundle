<?php

namespace DH\DoctrineAuditBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AuditController extends AbstractController
{
    /**
     * @Route("/audit", name="dh_doctrine_audit_list_audits", methods={"GET"})
     */
    public function listAuditsAction(): Response
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $reader->getEntities();

        return $this->render('@DHDoctrineAudit/Audit/audits.html.twig', [
            'audited' => $reader->getEntities(),
            'reader' => $reader,
        ]);
    }

    /**
     * @Route("/audit/{entity}/{id}", name="dh_doctrine_audit_show_entity_history", methods={"GET"})
     *
     * @param string     $entity
     * @param int|string $id
     * @param int        $page
     * @param int        $pageSize
     *
     * @return Response
     */
    public function showEntityHistoryAction(string $entity, $id = null, int $page = 1, int $pageSize = 50): Response
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $entries = $reader->getAudits($entity, $id, $page, $pageSize);

        return $this->render('@DHDoctrineAudit/Audit/entity_history.html.twig', [
            'id' => $id,
            'entity' => $entity,
            'entries' => $entries,
        ]);
    }

    /**
     * @Route("/audit/details/{entity}/{id}", name="dh_doctrine_audit_show_audit_entry", methods={"GET"})
     *
     * @param string     $entity
     * @param int|string $id
     *
     * @return Response
     */
    public function showAuditEntryAction(string $entity, $id): Response
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $data = $reader->getAudit($entity, $id);

        return $this->render('@DHDoctrineAudit/Audit/entity_history_entry.html.twig', [
            'entity' => $entity,
            'entry' => $data[0],
        ]);
    }
}
