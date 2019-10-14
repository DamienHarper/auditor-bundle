<?php

namespace DH\DoctrineAuditBundle\Controller;

use DH\DoctrineAuditBundle\Exception\AccessDeniedException;
use DH\DoctrineAuditBundle\Exception\InvalidArgumentException;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Reader\AuditEntry;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
     * @Route("/audit/revert/{hash}/{field}", name="dh_doctrine_audit_revert")
     *
     * @param $id
     * @param $object
     * @param $entity
     * @param $field
     *
     * @return RedirectResponse
     */
    public function revertEntityHistoryAction($hash, $field)
    {
        // get audit manager service
        $am = $this->container->get('dh_doctrine_audit.manager');
        // get audit reader service
        $reader = $this->container->get('dh_doctrine_audit.reader');
        // get audit entity manager
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $reverted_entity = $am->revert($reader, $em, $hash, $field);
        $entity_name = $em->getMetadataFactory()->getMetadataFor(get_class($reverted_entity))->getName();

        $em->persist($reverted_entity);
        $em->flush();

        return $this->redirectToRoute('dh_doctrine_audit_show_entity_history', [
            'entity' => $entity_name,
        ]);
    }

    /**
     * @Route("/audit/transaction/{hash}", name="dh_doctrine_audit_show_transaction", methods={"GET"})
     *
     * @param string $hash
     *
     * @return Response
     * @throws \Doctrine\ORM\ORMException
     *
     * @throws InvalidArgumentException
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
        $page = (int)$request->query->get('page', 1);
        $entity = AuditHelper::paramToNamespace($entity);

        $reader = $this->container->get('dh_doctrine_audit.reader');

        if (!$reader->getConfiguration()->isAuditable($entity)) {
            throw $this->createNotFoundException();
        }

        try {
            $entries = $reader->getAuditsPager($entity, $id, $page, AuditReader::PAGE_SIZE);
        } catch (AccessDeniedException $e) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('@DHDoctrineAudit/Audit/entity_history.html.twig', [
            'id' => $id,
            'entity' => $entity,
            'entries' => $entries,
        ]);
    }
}
