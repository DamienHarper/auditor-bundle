<?php

namespace DH\DoctrineAuditBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class AuditController extends Controller
{
    /**
     * @Method("GET")
     * @Template
     * @Route("/audit", name="dh_doctrine_audit_list_audits")
     */
    public function listAuditsAction()
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $reader->getEntities();

        return $this->render('DHDoctrineAuditBundle:Audit:audited_entities.html.twig', [
            'audited' => $reader->getEntities(),
        ]);
    }

    /**
     * @Method("GET")
     * @Template
     * @Route("/audit/{entity}/{id}", name="dh_doctrine_audit_show_entity_history")
     */
    public function showEntityHistoryAction(string $entity, int $id = null, int $page = 1, int $pageSize = 50)
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $entries = $reader->getAudits($entity, $id, $page, $pageSize);

        return $this->render('DHDoctrineAuditBundle:Audit:entity_history.html.twig', [
            'entity'     => $entity,
            'entries'   => $entries,
        ]);
    }

    /**
     * @Method("GET")
     * @Template
     * @Route("/audit/details/{entity}/{id}", name="dh_doctrine_audit_show_audit_entry")
     */
    public function showAuditEntryAction(string $entity, int $id)
    {
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $data = $reader->getAudit($entity, $id);

        return $this->render('DHDoctrineAuditBundle:Audit:entity_audit_details.html.twig', [
            'entity' => $entity,
            'entry' => $data[0],
        ]);
    }
}
