<?php

namespace DH\DoctrineAuditBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

class AuditController extends Controller
{
    /**
     * @Method("GET")
     * @Template
     * @Route("/audit", name="dh_doctrine_audit_audited_entities")
     */
    public function auditedEntitiesAction(Request $request)
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $reader->getAuditedEntities();

        return $this->render('DHDoctrineAuditBundle:Audit:audited_entities.html.twig', [
            'audited' => $reader->getAuditedEntities(),
        ]);
    }

    /**
     * @Method("GET")
     * @Template
     * @Route("/audit/{entity}/{id}", name="dh_doctrine_audit_entity_history")
     */
    public function entityHistoryAction(string $entity, int $id = null, int $page = 1, int $pageSize = 50)
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $results = $reader->getAudits($entity, $id, $page, $pageSize);

        return $this->render('DHDoctrineAuditBundle:Audit:entity_history.html.twig', [
            'entity'     => $entity,
            'entries'   => $results,
        ]);
    }

    /**
     * @Method("GET")
     * @Template
     * @Route("/audit/details/{entity}/{id}", name="dh_doctrine_audit_entity_audit_details")
     */
    public function entityAuditEntryAction(string $entity, int $id)
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $results = $reader->getAudit($entity, $id);

        return $this->render('DHDoctrineAuditBundle:Audit:entity_audit_details.html.twig', [
            'entity' => $entity,
            'entry' => $results[0],
        ]);
    }
}
