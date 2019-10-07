<?php

namespace DH\DoctrineAuditBundle\Controller;

use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Reader\AuditEntry;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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

        return $this->render('@DHDoctrineAudit/Audit/audits.html.twig', [
            'audited' => $reader->getEntities(),
            'reader' => $reader,
        ]);
    }

    /**
     * @Route("/audit/revert/{id}/{object}/{entity}/{field}", name="dh_doctrine_audit_revert")
     *
     * @param $id
     * @param $object
     * @param $entity
     * @param $field
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function revertEntityHistoryAction($id, $object, $entity, $field)
    {
        // get audit reader service
        $reader = $this->container->get('dh_doctrine_audit.reader');
        $am = $this->container->get('dh_doctrine_audit.manager');
        // get audit entity manager
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        /** @var AuditEntry $entity_audit */
        $entity_audit = $reader->getAudit(AuditHelper::paramToNamespace($entity), $id);
        $audited_entity = AuditHelper::paramToNamespace($entity);
        $current_entity = $em->getRepository($audited_entity)->find($object);

        // Get all differences
        $diffs = $entity_audit[0]->getDiffs();
        // get field value to revert
        $field_value = $diffs[$field]['old'];

        $setMethod = "set{$field}";

        $current_entity->{$setMethod}($field_value);

        $em->persist($current_entity);
        $em->flush();

        return $this->redirectToRoute('dh_doctrine_audit_show_entity_history', [
            'entity' => $entity,
        ]);
    }

    /**
     * @Route("/audit/transaction/{hash}", name="dh_doctrine_audit_show_transaction", methods={"GET"})
     *
     * @param string $hash
     *
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
     * @return Response
     */
    public function showEntityHistoryAction(Request $request, string $entity, $id = null): Response
    {
        $page = (int) $request->query->get('page', 1);
        $entity = AuditHelper::paramToNamespace($entity);

        $reader = $this->container->get('dh_doctrine_audit.reader');

        if (!$reader->getConfiguration()->isAuditable($entity)) {
            throw $this->createNotFoundException();
        }

        $entries = $reader->getAuditsPager($entity, $id, $page, AuditReader::PAGE_SIZE);

        return $this->render('@DHDoctrineAudit/Audit/entity_history.html.twig', [
            'id' => $id,
            'entity' => $entity,
            'entries' => $entries,
        ]);
    }
}
